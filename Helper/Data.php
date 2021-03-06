<?php

namespace Findify\Findify\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $scopeConfig;

    protected $storeManager;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function getFeedUrl()
    {
        $mediapath = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);

        $storeId = Mage_Core_Model_App::ADMIN_STORE_ID;
        $storeCode = (string)Mage::getSingleton('adminhtml/config_data')->getStore();
        $websiteCode = (string)Mage::getSingleton('adminhtml/config_data')->getWebsite();
        if ('' !== $storeCode) { // store level
            try {
                $storeId = Mage::getModel('core/store')->load( $storeCode )->getId();
            } catch (Exception $ex) {  }
        } elseif ('' !== $websiteCode) { // website level
            try {
                $storeId = Mage::getModel('core/website')->load( $websiteCode )->getDefaultStore()->getId();
            } catch (Exception $ex) {  }
        }

        $configfilename = $this->scopeConfig->getValue('attributes/feedinfo/feedfilename', \Magento\Store\Model\ScopeInterface::SCOPE_STORE,$storeId);
        $filename = str_replace("/", "", $configfilename);

        if(empty($filename)){
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $filename = 'jsonl_feed-'.$storeCode;
        }

        $fileurl = $mediapath.'findify/'.$filename.'.gz';

        return (string) $fileurl;
    }
    
    public function getFeedFileDate()
    {
        $storeId = Mage_Core_Model_App::ADMIN_STORE_ID;
        $storeCode = (string)Mage::getSingleton('adminhtml/config_data')->getStore();
        $websiteCode = (string)Mage::getSingleton('adminhtml/config_data')->getWebsite();
        if ('' !== $storeCode) { // store level
            try {
                $storeId = Mage::getModel('core/store')->load( $storeCode )->getId();
            } catch (Exception $ex) {  }
        } elseif ('' !== $websiteCode) { // website level
            try {
                $storeId = Mage::getModel('core/website')->load( $websiteCode )->getDefaultStore()->getId();
            } catch (Exception $ex) {  }
        }

        $mediapath = Mage::getBaseDir('media');

        $configfilename = $this->scopeConfig->getValue('attributes/feedinfo/feedfilename', \Magento\Store\Model\ScopeInterface::SCOPE_STORE,$storeId);
        $filename = str_replace("/", "", $configfilename);

        if(empty($filename)){
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $filename = 'jsonl_feed-'.$storeCode;
        }

        $filepath = $mediapath.'/findify/'.$filename.'.gz';

        if (file_exists($filepath)) {
            $timezone = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            date_default_timezone_set($timezone);
            return date("F d Y H:i:s", filemtime($filepath));
        }else{
            return "$filepath does not exist yet";
        }
    }
    
    public function getFeedIsRunning()
    {
    	$pendingSchedules = Mage::getModel('cron/schedule')->addFieldToFilter('job_code', array('eq' => 'findifyfeed_crongeneratefeed'))
            ->addFieldToFilter('status', Mage_Cron_Model_Schedule::STATUS_RUNNING)
            ->addFieldToFilter('executed_at', array('neq' => 'NULL'))
            ->load();

	if($pendingSchedules->getSize() > 0) {
	    $isRunning = "Yes";
	}else{
	    $isRunning = "No";
        }

	return $isRunning;
    
    }
    
    public function getJSTag()
    {
        $storeId = $this->storeManager->getStore()->getStoreId();
        $findifyJsTag = $this->scopeConfig->getValue('attributes/analytics/jstag', \Magento\Store\Model\ScopeInterface::SCOPE_STORE,$storeId);
        $findifyJsTagWrapped = "<script type=\"text/javascript\" src=\"".$findifyJsTag."\"></script>"; // Value is returned from helper so we do not need CDATA enclosing here
        return $findifyJsTagWrapped;
    }
    
}
