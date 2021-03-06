<?php

namespace Findify\Findify\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Catalog\Helper\Image;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Store\Model\StoreRepository;

class Cron
{
    protected $appEmulation;
    protected $attributeRepository;
    protected $categoryFactory;
    protected $categoryRepository;
    protected $filterBuilder;
    protected $imageHelperFactory;
    protected $logger;
    protected $productImageHelper;
    protected $productRepository;
    protected $productRepositoryFactory;
    protected $scopeConfig;
    protected $searchCriteriaBuilder;
    protected $storeManager;
    protected $catalogProductTypeConfigurable;
    protected $catalogProductTypeGrouped;
    protected $localeDate;
    protected $storeRepository;
    protected $productMetadata;
    protected $moduleList;
    protected $directoryList;
    protected $objectManager;
    protected $bundleChildren;
    
    public function __construct(
        \Findify\Findify\Model\Catalog\ProductRepository $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        \Psr\Log\LoggerInterface $logger,
        Image $productImageHelper,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
	\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        AttributeRepositoryInterface $attributeRepository,
	\Magento\Store\Model\StoreManagerInterface $storeManager,
	\Magento\Store\Model\App\Emulation $appEmulation,
	\Magento\Catalog\Api\ProductRepositoryInterfaceFactory $productRepositoryFactory,
	\Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $catalogProductTypeConfigurable,
	\Magento\GroupedProduct\Model\Product\Type\Grouped $catalogProductTypeGrouped,
	StoreRepository $storeRepository,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
	\Magento\Framework\App\ProductMetadataInterface $productMetadata,
	\Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Catalog\Helper\ImageFactory $imageHelperFactory,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->logger = $logger;
        $this->image = $productImageHelper;
        $this->directoryList = $directoryList;
        $this->attributeRepository = $attributeRepository;
        $this->categoryFactory = $categoryFactory;
	$this->scopeConfig = $scopeConfig;
	$this->storeManager = $storeManager;
	$this->appEmulation = $appEmulation;
	$this->productRepositoryFactory = $productRepositoryFactory;
	$this->imageHelperFactory = $imageHelperFactory;
	$this->catalogProductTypeGrouped = $catalogProductTypeGrouped;
	$this->catalogProductTypeConfigurable = $catalogProductTypeConfigurable;
        $this->localeDate = $localeDate;
	$this->storeRepository = $storeRepository;
	$this->productMetadata = $productMetadata;
	$this->moduleList = $moduleList;
        $this->objectManager = $objectManager;
    }

    public function export()
    {
        $stores = $this->storeRepository->getList();

        $pubFolder = $this->directoryList->getPath('pub');                    
        $fileextradata = $pubFolder.'/media/findify/feedextradata.gz';
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
	$jsonextradata = array();
        $extradata = array(
        	'extension_version' => $this->moduleList->getOne('Findify_Findify')['setup_version'],
                'magento_version' => $this->productMetadata->getVersion(),
                'feeds' => array()
        );

        $_products_with_children = array('configurable', 'grouped', 'bundle');

        ini_set('memory_limit','-1'); // if store has more than 10000 products, default php memory limits will probably be too low

        foreach ($stores as $eachStore) {
	    $storeCode = $eachStore["code"];
	    $storeId = $eachStore["store_id"];
	    $this->storeManager->setCurrentStore($storeId);

	    $feedisenabled = $this->scopeConfig->getValue('attributes/schedule/isenabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeCode);
            if($feedisenabled){

                    $jsondata = array();
                $productTypes = [
                    ['in' => 'bundle'],
                    ['nin' => 'bundle']
                ];

                    $starttime = new \DateTime('NOW');
                    // get filename from system configuration, or use default json_feed-<storeCode> if empty
                    $configfilename = $this->scopeConfig->getValue('attributes/feedinfo/feedfilename', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeCode);
                    $filename = str_replace("/", "", $configfilename);
        	    $file = $pubFolder . '/media/findify/' . $filename . '.gz';
                    if(empty($filename)){
                        $filename = 'jsonl_feed-'.$storeCode;
                    }


                foreach ($productTypes as $typeFilter) {

                    $searchResults = $this->productRepository->getListForStore($storeId, $typeFilter);
                    $items = $searchResults->getItems();

                    if (count($items) > 0) {
        
                    foreach ($items as $item) {
                        $product_data = array();
                        $product_data['id'] = $item->getId();
                        $product_data['sku'] = $item->getSku();
                        $product_data['visibility'] = $item->getVisibility();
                        $product_data['type_id'] = $item->getTypeId();
                        
                        // we use created_at or news_from_date attribute value, whichever is newer
                        $createdAt = $item->getCreatedAt();
                        $newsFromDate = $item->getNewsFromDate();
                        if($newsFromDate && (strtotime($newsFromDate) > strtotime($createdAt))){
                            $datetime = new \DateTime($newsFromDate);
                        }else{
                            $datetime = new \DateTime($createdAt);
                        }
                        $product_data['created_at'] = $datetime->format(\DateTime::ATOM);

                        // if this product has children, set id as item_group_id                      
                            if (in_array($product_data['type_id'], $_products_with_children)) {
                            $product_data['item_group_id'] = $product_data['id'];
                        }else{
                            $product_data['item_group_id'] = '';
                        }

                        // Product categories as breadcrumbs Father > Child > Grandchild
                        $pathArray = array();

                        if ($categoryIds = $item->getCategoryIds()) {
                            foreach ($categoryIds as $categoryId) {

                                $category = $this->categoryRepository->get($categoryId);
                                $pathIds = explode('/', $category->getPath()); // getPath() returns category IDs path as '1/2/53', we store that as an array ['1','2','53']

                                $pathByName = array();
                                foreach($pathIds as $pathId){
                                        $category = $this->categoryFactory->create()->load($pathId);
                                        $categoryName = $category->getName();
                                        if ($categoryName != ""){
                                            $pathByName[] = $categoryName;
                                        }
                                }
                                array_splice($pathByName, 0, 2); // remove first two elements in path, which are always: Root Catalog > Default Category
                                $pathArray[] = implode(' > ', $pathByName);
                            }
                        }
                        $product_data['category'] = $pathArray;

                        $product_data['title'] = $item->getName(); // name

                            if ($item->getTypeId() == \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE) {
                                $calculator = $this->objectManager->get(\Findify\Findify\Pricing\Bundle\Adjustment\Calculator::class);
                                $arguments = [
                                    'saleableItem' => $item,
                                    'quantity' => 1,
                                    'calculator' => $calculator
                                ];
                                $bundleObj = $this->objectManager->create(\Magento\Bundle\Pricing\Price\FinalPrice::class, $arguments);
                                $_minimal_price = $bundleObj->getMinimalPrice();
                                $product_data['price'] = sprintf('%0.2f', $_minimal_price->getValue()); // price excl tax, two
                                # store selected child ids
                                $_selection_ids = $calculator->getSelectionIds();
                                foreach ($_selection_ids as $_child_id) {
                                    $this->bundleChildren[$_child_id][] = $product_data['id'];
                                }
                            } else {
                        $product_data['price'] = sprintf('%0.2f',$item->getPrice()); // price excl tax, two decimal places
                            }

                        $product_data['product_url'] = $item->getProductUrl(); // full url

                        $specialprice = $item->getSpecialPrice();
                        if ($specialprice &&  $specialprice < $item->getPrice()){
                            $specialPriceFromDate = $item->getSpecialFromDate();
                            $specialPriceToDate = $item->getSpecialToDate();
                            if ($this->localeDate->isScopeDateInInterval($storeId, $specialPriceFromDate, $specialPriceToDate)) {
                                $product_data['sale_price'] = sprintf('%0.2f',$specialprice);
                            }
                        }        

                        // Product availability, as Magento calculates it
                        $product_data['availability'] = $item->isSaleable() ? "in stock" : "out of stock";

                        // images
                        $this->appEmulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
                        $product_data['image_url'] = $this->imageHelperFactory->create()->init($item, 'product_base_image')->getUrl();
                        $product_data['thumbnail_url'] = $this->imageHelperFactory->create()->init($item, 'product_thumbnail_image')->getUrl();
                        $this->appEmulation->stopEnvironmentEmulation();
                                                                                        
                        // Long and short descriptions
                        $product_data['description'] = $item->getDescription();
                        $product_data['short_description'] = $item->getShortDescription();

                        // User selected attributes via System / Configuration:
                        $selectedattributes = $this->scopeConfig->getValue('attributes/general/attributes', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeCode);
                        if ($selectedattributes) {
                            $selectedattributes = $this->_unserialize($selectedattributes);
                        }

                        // User selected attributes via System / Configuration:
                        if (is_array($selectedattributes)) {
                            foreach($selectedattributes as $selectedattributesRow) {
                                    if (!isset($selectedattributesRow['active']))
                                        continue;

                                $attrfilelabel = $selectedattributesRow['active'];
                                $attrname = $selectedattributesRow['customer_group'];
                                $attributecontent = $item->getAttributeText($attrname);
			    	/* for text attributes: */
			    	if(!$attributecontent)
					$attributecontent = $item->getData($attrname);

                                if (!empty($attributecontent)){
                                  $product_data[$attrfilelabel] = $attributecontent;
                                }else{
                                  $product_data[$attrfilelabel] = '';
                                }
                            }
                        }

                        $jsondata[] = json_encode($product_data)."\n"; // Add this product data to main json array

                        if($item->getTypeId() == "simple"){ // if product is not simple, it can not have parents
                            $groupParentsIds = $this->catalogProductTypeGrouped->getParentIdsByChild($item->getId());
                            if(isset($groupParentsIds[0])){ // it belongs to at least one grouped product
                                foreach($groupParentsIds as $parentId) {
                                    $product_data_in_group = $product_data; // we add to the feed a copy of the simple product for each group that it belongs to, modifying item_group_id in each instance
                                    $product_data_in_group['item_group_id'] = $parentId;
                                    $jsondata[] = json_encode($product_data_in_group)."\n";
                                }
                            }
                            
                            $configurableParentsIds = $this->catalogProductTypeConfigurable->getParentIdsByChild($item->getId());
                            if(isset($configurableParentsIds[0])){ // it belongs to at least one configurable product
                                foreach($configurableParentsIds as $parentId) {
                                    $product_data_in_configurable = $product_data; // we add to the feed a copy of the simple product for each configurable that it belongs to, modifying item_group_id in each instance
                                    $product_data_in_configurable['item_group_id'] = $parentId; // child products are added once for each parent, setting item_group_id with the parents' ids
                                    $jsondata[] = json_encode($product_data_in_configurable)."\n"; // Add this product data to main json array
                                }
                            }

                                if (isset($this->bundleChildren[$product_data['id']])) { // it belongs to at least one bundle product
                                    foreach ($this->bundleChildren[$product_data['id']] as $parentId) {
                                        $product_data_in_bundle = $product_data; // we add to the feed a copy of the simple product for each group that it belongs to, modifying item_group_id in each instance
                                        $product_data_in_bundle['item_group_id'] = strval($parentId);
                                        $jsondata[] = json_encode($product_data_in_bundle) . "\n"; // Add this product data to main json array
                                    }
                                }
                            }
                    } // end foreach items as item
                    } // end if count items > 0
                }// end foreach filterType
                    

                if (count($jsondata) > 0) {
                    // Write product feed array to gzipped file
                    file_put_contents("compress.zlib://$file", $jsondata);
                    // Extra data file
                    $endtime = new \DateTime('NOW');
                    $runinterval = $starttime->diff($endtime);
                    $elapsed = $runinterval->format('%S'); // elapsed seconds
                    $extradata['feeds'][$storeCode] = array(
                            'feed_url' => $baseUrl.$file,
                            'last_generation_duration' => $elapsed,
                            'last_generation_time' => $starttime
                    );
                }
	    	} // end if($feedisenabled)
        } // end eachStore

	$jsonextradata[] = json_encode($extradata);
        file_put_contents("compress.zlib://$fileextradata", $jsonextradata);
    }

// end export()
	
	
	
	protected function _unserialize($string)
    {
        $result = json_decode($string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Unable to unserialize value.');
        }
        return $result;
    }
	
}
