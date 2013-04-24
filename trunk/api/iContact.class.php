<?php

class iContact {

	const STATUS_CODE_SUCCESS = 200;
	private $apiUrl;
	private $username;
	private $password;
	private $appId;
	private $accountId;
	private $clientFolderId;
	public $debugMode;

	/**
	 *
	 * @param type $apiUrl
	 * @param type $username
	 * @param type $password
	 * @param type $appId
	 * @param type $accountId
	 * @param type $clientFolderId
	 * @param type $debugMode
	 */
	public function __construct($apiUrl = '', $username = '', $password = '', $appId = '', $accountId = '', $clientFolderId = null, $debugMode = false) {

		$this->settings = $settings = (array)get_option("gf_icontact_settings");

		/** You've got to be able to manage options to see debug mode. */
		$this->debugMode = current_user_can('manage_options') && ($settings['debug'] || $debugMode || isset($_REQUEST['debug']));

		$this->sandbox = !empty($settings['sandbox']);
		$this->apiUrl = $this->getApiUrl();
		if($this->sandbox) {
			$this->username = $settings['sandbox-username'];
			$this->password = $settings['sandbox-password'];
			$this->appId = $settings['sandbox-appid'];
		} else {
			$this->username = $settings['username'];
			$this->password = $settings['password'];
			$this->appId = $settings['appid'];
		}
		$this->accountId = empty($settings['accountid']) ? $this->getAccountId() : $settings['accountid'];
		$this->clientFolderId = empty($settings['clientfolderid']) ? $this->getClientFolderId() : $settings['clientfolderid'];

		if(is_array($this->settings) && array_diff($this->settings, $settings)) {
			update_option('gf_icontact_settings', $this->settings);
		}
	}

	public function getApiUrl() {
		if($this->sandbox) {
			$apiUrl = 'https://app.sandbox.icontact.com/icp'; // $apiUrl;
		}	else {
			$apiUrl = 'https://app.icontact.com/icp'; // $apiUrl;
		}
		return $apiUrl;
	}

	public function getClientFolderId() {
		if(empty($this->accountId)) { return false; }
		if(isset($this->settings) && !empty($this->settings['clientfolderid'])) {
			return $this->settings['clientfolderid'];
		}
		$response = $this->callResource("/a/{$this->accountId}/c",'GET');
		if(isset($response['data']) && isset($response['data']['clientfolders'])) {
			foreach($response['data']['clientfolders'] as $clientfolder) {
				$this->clientFolderId = $clientfolder['clientFolderId'];
			}
		}
		$this->settings['clientfolderid'] = $this->clientFolderId;
		return $this->clientFolderId;
	}

	public function setClientFolderId($clientFolderId) {
		$this->clientFolderId = $clientFolderId;
	}

	public function setDebugMode($debugMode) {
		$this->debugMode = $debugMode;
	}

	public function getAccountId() {
		if(isset($this->settings) && !empty($this->settings['accountid'])) {
			return $this->settings['accountid'];
		}

		$response = $this->callResource("/a/",'GET');
		if(isset($response['data']) && isset($response['data']['accounts'])) {
			foreach($response['data']['accounts'] as $account) {
				$this->accountId = $account['accountId'];
			}
		}

		$this->settings['accountid'] = $this->accountId;

		return $this->accountId;
	}

	// Added by KWS
	public function testSettings() {
		$response = $this->callResource("/a/",'GET');
		$this->dump($response, 'Testing Account Settings');
		return $response;
	}

	/**
	 * Create multiple contacts
	 * @param array $contacts contains list of contacts with 'email', 'firstName', 'lastName'
	 * @return array contactIds
	 */
	public function createContacts($contacts) {
		$contactIds = null;

		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/contacts",'POST', $contacts);

		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			if(count($response['data']['contacts']) > 0) {
				foreach($response['data']['contacts'] as $contact) {
					$contactIds[$contact['email']] = $contact['contactId'];
				}
			}
		} else {
			$this->lastError = 'iContact returned ' . $response['code'];
			return false;
		}
		if($this->debugMode) $this->dump($response);

		return $contactIds;
	}

	/**
	 * Create a contact
	 * @param string $email
	 * @param string $firstName
	 * @param string $lastName
	 * @return string $contactId
	 */
	public function createContact($email, $fields = array()) {
		$fields['email'] = $email;
		$result = $this->createContacts(array($fields));
		if(is_null($result))
			$contactId = null;
		else
			$contactId = array_shift($result);

		return $contactId;
	}

	/**
	 * Delete a contact
	 * @param string $contactId
	 * @return boolean $success
	 */
	public function deleteContact($contactId) {
		$success = false;
		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/contacts/$contactId",'DELETE');

		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			$success = true;
		} else {
			$this->lastError = 'iContact returned ' . $response['code'];
			return false;
		}
		if($this->debugMode) {
			$this->dump($response);
		}
		return $success;
	}
	/**
	 * Create one or more lists
	 * @param array $lists
	 * @return string listId of new created list
	 */
	public function createLists($lists) {
		$listIds = null;
		$this->addDefaultsToLists($lists);
		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/lists",'POST', $lists);

		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			if(count($response['data']['lists']) > 0) {
				foreach($response['data']['lists'] as $list) {
					$listIds[$list['name']] = $list['listId'];
				}
			}
		} else {
			$this->lastError = 'iContact returned ' . $response['code'];
			return false;
		}
		if($this->debugMode) {
			$this->dump($response);
		}
		return $listIds;
	}

	/**
	 * Create a list
	 * @param string $listName
	 * @param string $welcomeMessageId
	 * @param bool $emailOwnerOnChange
	 * @param bool $welcomeOnManualAdd
	 * @param bool $welcomeOnSignupAdd
	 * @return string listId
	 */
	public function createList($listName, $welcomeMessageId, $emailOwnerOnChange = 0, $welcomeOnManualAdd = 0, $welcomeOnSignupAdd = 0) {
		$result = $this->createLists(array(array(
			'name'					=> $listName,
			'welcomeMessageId'		=> $welcomeMessageId,
			'emailOwnerOnChange'	=> $emailOwnerOnChange,
			'welcomeOnManualAdd'	=> $welcomeOnManualAdd,
			'welcomeOnSignupAdd'	=> $welcomeOnSignupAdd
		)));

		if(is_null($result))
			$listId = null;
		else
			$listId = array_shift($result);

		return $listId;
	}

	/**
	 * Delete a list
	 * @param string $listId
	 * @return boolean $success
	 */
	public function deleteList($listId) {
		$success = false;
		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/lists/$listId",'DELETE');

		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			$success = true;
		} else {
			$this->lastError = 'iContact returned ' . $response['code'];
			return false;
		}
		if($this->debugMode) {
			$this->dump($response);
		}
		return $success;
	}

	/**
	 * Get an array containing all available lists
	 * @return array
	 */
	public function getLists() {
		$lists;

		$lists = get_site_transient('icgf_lists');
		if($lists && (!isset($_REQUEST['refresh']) || (isset($_REQUEST['refresh']) && $_REQUEST['refresh'] !== 'lists'))) {
			return $lists;
		}

		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/lists?limit=999",'GET');
		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			$lists = $response['data']['lists'];
		} else {
			return false;
		}
		if($this->debugMode) {
			$this->dump($response);
		}

		set_site_transient('icgf_lists', $lists);

		return $lists;
	}

	/**
	 * Get an array containing all available custom fields
	 * @return array
	 */
	public function getCustomFields() {
		$lists;

		$fields = get_site_transient('icgf_cf');
		if($fields && (!isset($_REQUEST['refresh']) || (isset($_REQUEST['refresh']) && $_REQUEST['refresh'] !== 'customfields'))) {
			return $fields;
		}


		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/customfields?limit=999",'GET');
		if($this->debugMode) { $this->dump($response); }
		if ($response['code'] == self::STATUS_CODE_SUCCESS) {
			$fields = isset($response['data']['customfields']) ? $response['data']['customfields'] : false;
			if(!$fields) { return false; }
		} else {
			return false;
		}

		set_site_transient('icgf_cf', $fields, 60*60*24*7);

		return $fields;
	}

	/**
	 * Add an array of contacts to a list
	 * @param string $listId
	 * @param array $contactIds
	 */
	public function subscribeContactsToList($listId, $contactIds) {
		if(!is_array($contactIds) || count($contactIds) < 1) {
			$this->lastError = 'contactIds array is empty or invalid';
			return false;
		}
		foreach($contactIds as $contactId) {
			$contacts[] = array('contactId'=>$contactId, 'listId'=>$listId, 'status'=>'normal');
		}
		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/subscriptions", 'POST', $contacts);

		if ($response['code'] != self::STATUS_CODE_SUCCESS) {
			$this->lastError = 'iContact returned ' . $response['code'];
			return false;
		}
		if($this->debugMode) $this->dump($response);
		return $response;
	}

	/**
	 * Send an email to a list or lists
	 * @param String $messageId
	 * @param String $listId can be a comma seperated list of listIds
	 */
	public function sendEmail($messageId, $listId) {
		$response = $this->callResource("/a/{$this->accountId}/c/{$this->clientFolderId}/sends",'POST', array(
			array (
				'messageId'			=> $messageId,
				'includeListIds'	=> $listId,
			)
		));
		if ($response['code'] != self::STATUS_CODE_SUCCESS) {
			$this->lastError = sprintf(__('iContact returned %s', 'gravity-forms-icontact'), $response['code']);
			return false;
		}
		if($this->debugMode) $this->dump($response);
	}
	/**
	 * Function to make the curl request
	 * @param string $url
	 * @param type $method
	 * @param type $data
	 * @return type
	 */
	protected function callResource($url = '', $method, $data = null) {
		global $wp_version;

		if(!in_array($method, array('POST', 'PUT', 'DELETE', 'GET'))) { $api->lastError = "$method is not a supported method"; return false; }

		$url =  untrailingslashit($this->apiUrl) . $url;

		$headers = array(
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
			'Api-Version' => '2.2',
			'Api-AppId' => $this->appId,
			'Api-Username' => $this->username,
			'Api-Password' => $this->password,
			'user-agent' => 'Gravity Forms iContact Addon plugin - WordPress/'.$wp_version.'; '.get_bloginfo('url')
		);

		// Set SSL verify to false because of server issues.
		$args = array(
			'body' 		=> json_encode($data),
			'method'	=> strtoupper($method),
			'headers' 	=> $headers,
			'sslverify'	=> false,
			'timeout'	=> 30
		);

		$result = wp_remote_request($url, $args);

		if(is_wp_error($result)) {
			$error = $result->get_error_message();
			$this->lastError = $error;
			return false;
		}

		if($this->debugMode) {
			$this->dump(array('$url' => $url, '$args' => $args, '$result' => $result), __('Request sent to iContact', 'gravity-forms-icontact'));
		}

		$this->lastRequest = $result;

		$body = wp_remote_retrieve_body($result);

		if((isset($result['headers']['content-type']) && $result['headers']['content-type'] == 'text/html') || strpos($body, '<form action="sentry.php"')) {
			$api->lastError = "Didn't work. Something went quite wrong.";
			return false;
		}

		$body = json_decode($body, true);

		if (wp_remote_retrieve_response_code($result) != self::STATUS_CODE_SUCCESS || !empty($body['errors'])) {
			foreach($body['errors'] as $error) {
				$this->lastError .= $error;
			}
		}

		return array(
			'code' => wp_remote_retrieve_response_code($result),
			'data' => $body,
		);
	}

	public function dump($array, $title = '') {
		if(!is_admin() && current_user_can('gravityforms_icontact')) {
			if(!empty($title)) {
				echo '<h3>'.$title.'</h3>';
				echo '<h5 style="font-weight:normal;">'.__(sprintf('Note: This is visible only to users who can manage options. You have "Debug Form Submissions for Administrators" turned on in the %splugin settings%s.', '<a href="' . admin_url( 'admin.php?page=gf_settings&addon=iContact' ).'">', '</a>'),'gravity-forms-icontact').'</h5>';
			}
			echo "<pre>" . print_r($array, true) . "</pre>";
		}
	}

	private function addDefaultsToLists(&$lists) {
		foreach($lists as $list) {
			$list['emailOwnerOnChange']	= 0;
			$list['welcomeOnManualAdd']	= 0;
			$list['welcomeOnSignupAdd']	= 0;
		}
	}

	private function addDefaultsToList(&$list) {
		addDefaultsToLists($list);
	}
}

