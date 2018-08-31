<?php

class StaticHtmlOutput_Options
{
	protected $_options = array();
	
	protected $_optionKey = null;
	
	public function __construct($optionKey) {
		$options = get_option($optionKey);
		
		if (false === $options)
		{
			$options = array();
		}
		
		$this->_options = $options;
		$this->_optionKey = $optionKey;
	}
	
	public function __set($name, $value) {
		$this->_options[$name] = $value;
		return $this;
	}
	
	public function setOption($name, $value) {
		return $this->__set($name, $value);
	}
	
	public function __get($name) {
		$value = array_key_exists($name, $this->_options) ? $this->_options[$name] : null;
		return $value;
	}
	
	public function getOption($name) {
		return $this->__get($name);
	}
	
	public function save() {
		return update_option($this->_optionKey, $this->_options);
	}
	
	public function delete() {
		return delete_option($this->_optionKey);
	}

  public function getSettingKeys() {
    return array(
      'selected_deployment_option',
      'baseUrl',
      'diffBasedDeploys',
      'sendViaGithub',
      'sendViaFTP',
      'sendViaS3',
      'sendViaNetlify',
      'sendViaDropbox',
      'additionalUrls',
      'dontIncludeAllUploadFiles',
      'outputDirectory',
      'targetFolder',
      'githubRepo',
      'githubPersonalAccessToken',
      'githubBranch',
      'githubPath',
      'rewriteWPCONTENT',
      'rewriteTHEMEROOT',
      'rewriteTHEMEDIR',
      'rewriteUPLOADS',
      'rewritePLUGINDIR',
      'rewriteWPINC',
      'useRelativeURLs',
      'useBaseHref',
      'basicAuthUser',
      'basicAuthPassword',
      'bunnycdnPullZoneName',
      'bunnycdnAPIKey',
      'bunnycdnRemotePath',
      'cfDistributionId',
      's3Key',
      's3Secret',
      's3Region',
      's3Bucket',
      's3RemotePath',
      'dropboxAccessToken',
      'dropboxFolder',
      'netlifySiteID',
      'netlifyPersonalAccessToken',
      'ftpServer',
      'ftpUsername',
      'ftpPassword',
      'ftpRemotePath',
      'useActiveFTP',
      'allowOfflineUsage'
    );
  }
}
