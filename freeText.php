<?php
class FreeText {

	/** Makes a curl request
	 *
	*/
	protected function curl_redir_exec($ch, $cookie=null, $postVars=null, $last_url=null){
	    static $curl_loops = 0;
	    static $curl_max_loops = 4;
	    $old_postVars = $postVars;

	    if($curl_loops++ >= $curl_max_loops){
			$curl_loops = 0;
			return false;
	    }
	    if(isset($last_url)&&$last_url!==null){
			curl_setopt($ch, CURLOPT_REFERER, $last_url);
	    }
	    if(isset($postVars)&&$postVars!==null){
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postVars);
			$postVars = null;
	    }

	    curl_setopt($ch, CURLOPT_HEADER, true);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt ($ch, CURLOPT_COOKIEFILE, $cookie);
	    curl_setopt ($ch, CURLOPT_COOKIEJAR, $cookie);
	    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2125.104 Safari/537.36");
	    $data = curl_exec($ch);

	    list($header, $data) = explode("\r\n\r\n", $data, 2);
	    while($header=="HTTP/1.1 100 Continue"){
			list($header, $data) = explode("\r\n\r\n", $data, 2);
	    }
	    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	    if ($http_code == 301 || $http_code == 302){
			$matches = array();
			preg_match('/Location:(.*?)\n/', $header, $matches);
			$url = @parse_url(trim(array_pop($matches)));
			if (!$url){
				//couldn't process the url to redirect to
				$curl_loops = 0;
				return $data;
			}
			$last_url = parse_url(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
			if (!$url['scheme'])
				$url['scheme'] = $last_url['scheme'];
			if (!$url['host'])
				$url['host'] = $last_url['host'];
			if (!$url['path'])
				$url['path'] = $last_url['path'];
			$new_url = $url['scheme'].'://'.$url['host'].$url['path'].(array_key_exists('query', $url) && $url['query'] ? '?'.$url['query'] : '');
			curl_setopt($ch, CURLOPT_URL, $new_url);
			$last_url = $last_url['scheme'].'://'.$last_url['host'].$last_url['path'];
			return $this->curl_redir_exec($ch, $cookie, $postVars, $last_url);
	    } else {
			$curl_loops=0;
			return $data;
	    }
	}

	/** Convert map to string of url encoded values
	 * @param map The map of post fields and values
	*/
	protected function preparePostFields($array) {
	    $params = array();
	    foreach ($array as $key => $value) {
		$params[] = $key . '=' . urlencode($value);
	    }
	    return implode('&', $params);
	}

	/** Extract the numbers from a string and return them in a string
	 * @param number The string to extract the numbers from
	*/
	protected function formatPhoneNumber($number){
	    preg_match_all('/\d+/', $number, $matches);
	    return implode($matches[0]);
	}

	/** Send a free text
	 * @param number A string containing their phone number in almost any format
	 * @param carrier A string indicating which carrier
	 * @param sender A string saying what email address the message is sent from
	 * @param message A string containing the message to send
	*/
	public function send_text($number, $carrier, $sender, $message){
		$number = $this->formatPhoneNumber($number);
		// Carrier email suffixes
		define('ATT', 'txt.att.net');
		define('SPRINT', 'messaging.sprintpcs.com');
		define('TMOBILE', 'tmomail.net');
		define('US_CELLULAR', 'email.uscc.net');
		define('VERIZON', 'vtext.com');
		define('VIRGIN_MOBILE', 'vmobl.com');
		define('BOOST_MOBILE', 'myboostmobile.com');
		// Message parameters
		define('MAX_SMS_LENGTH', 150);
		define('DEFAULT_CELL_SENDER', $sender);

		switch($carrier){
		case "verizon":
			$carrier = VERIZON;
			break;
		case "att":
			$carrier = ATT;
			break;
		case "sprint":
			$carrier = SPRINT;
			break;
		case "t-mobile":
			$carrier = TMOBILE;
			break;
		case "virgin_mobile":
			$carrier = VIRGIN_MOBILE;
			break;
		case "us_cellular":
			$carrier = US_CELLULAR;
			break;
		case "boost_mobile":
			$carrier = BOOST_MOBILE;
			break;
		default:
			return false;
		}
		// Keep a notifier of whether the message was sent or not
		$Success = false;
		// Define the recipient address
		$Recipient = $number . '@' . $carrier;
		// Find out how many message will have to be sent
		$message = stripslashes($message);
		$StartIndex = 0;
		$messages = array();
		while(true){
			$temp_msg = substr($message, $StartIndex, MAX_SMS_LENGTH);
			if(strlen($temp_msg)<MAX_SMS_LENGTH){
				$messages[] = $temp_msg;
				break;
			}
			$index = strrpos($temp_msg, " ");
			if(!$index){
				$index = MAX_SMS_LENGTH - 1;
			}
			$temp_msg = substr($temp_msg, 0, $index);
			$StartIndex += $index;
			$messages[] = $temp_msg;
		}
		for($i = 0; $i<sizeof($messages); $i++){
			$Message = $messages[$i];
			$Message .= ' ('.($i + 1).'/'.sizeof($messages).')';
			$Success &= mail($Recipient, null, $Message, 'From: ' . DEFAULT_CELL_SENDER);
		}
		return $Success;
	}

	/** Return the carrier for a US phone number
	 * phone: the 10 digit phone number in almost any format
	*/
	public function getCarrier($phone){
		$phone = $this->formatPhoneNumber($phone);
		if(strlen($phone)!=10){
			return false;
		}
		//Verizon
		$post_data = array(
			'path'=>'processMultiMdn',
			'operationId'=>'eligibility',
			'phoneNumber1'=>$phone,
			'Check+Eligibility'=>'',
			'phoneNumber2'=>'',
			'phoneNumber3'=>'',
			'phoneNumber4'=>'',
			'phoneNumber5'=>''
		);
		$post_data = $this->preparePostFields($post_data);
		$url = "http://www.verizonwireless.com/b2c/LNPControllerServlet";
		$ch = curl_init($url);
		$result = $this->curl_redir_exec($ch, null, $post_data, "http://www.verizonwireless.com/b2c/support/switch-to-verizon");
		curl_close($ch);
		$data = strtolower($result);
		$verizon = strpos($data, "you are currently a verizon wireless customer");
		if($verizon){
			$carrier = "verizon";
		}
		else{
			//ATT
			$ckfile = tempnam("./tmp", "attCookie");
			$npa = substr($phone, 0, 3);
			$nxx = substr($phone, 3, 3);
			$ext = substr($phone, 6, 4);
			$post_data = array(
			'_dyncharset'=>'UTF-8',
			'_dynSessConf'=>'23',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.npa'=>$npa,
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.npa'=>' ',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.nxx'=>$nxx,
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.nxx'=>' ',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.line'=>$ext,
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkoutSessionBean.lnpInfoBeans%5B0%5D.line'=>' ',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkLNPEligibility.x'=>'0',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkLNPEligibility.y'=>'0',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkLNPEligibility'=>'submit',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.checkLNPEligibility'=>' ',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.deleteLnpIndex'=>'',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.deleteLnpIndex'=>' ',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.delete'=>'',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.add'=>'',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.successURL'=>'/shop/wireless/transferyournumber.html',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.successURL'=>' ',
			'%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.errorURL'=>'/shop/wireless/transferyournumber.html',
			'_D%3A%2Fatt%2Fecom%2Fshop%2Fview%2Fformhandler%2Fwireless%2FLNPEligibilityModalFormHandler.errorURL'=>' ',
			'_DARGS'=>'/shop/wireless/transferyournumber.html'
			);
			$post_data = $this->preparePostFields($post_data);

			$url = "http://www.att.com/shop/wireless/transferyournumber.html";
			$url2 = "http://www.att.com/shop/wireless/transferyournumber.html?_DARGS=/shop/wireless/transferyournumber.html";
			$ch = curl_init($url);
			$result = $this->curl_redir_exec($ch, $ckfile, null, "http://www.att.com/shop/wireless/transferyournumber.html");

			curl_setopt($ch, CURLOPT_URL, $url2);
			$result = $this->curl_redir_exec($ch, $ckfile, $post_data, "http://www.att.com/shop/wireless/transferyournumber.html");
			curl_close($ch);

			$ch = curl_init($url);
			$result = $this->curl_redir_exec($ch, $ckfile, null, "http://www.att.com/shop/wireless/transferyournumber.html");
			curl_close($ch);

			$data = strtolower($result);
			$index = strpos($data, "not eligible");
			if($index){
				$carrier = "att";
			}
			else{
				//Sprint
				$zip = intval($ext)%3 == 0 ? "27516" : (intval($ext)%3 == 1) ? "27514" : "63333";
				$post_data = array(
					'normalMethod'=>'true',
					'zipCode'=>$zip,
					'portInNumberListBean.portInNumbers[0].npa'=>$npa,
					'portInNumberListBean.portInNumbers[0].nxx'=>$nxx,
					'portInNumberListBean.portInNumbers[0].ext'=>$ext,
					'portInNumberListBean.portInNumbers[1].npa'=>'',
					'portInNumberListBean.portInNumbers[1].nxx'=>'',
					'portInNumberListBean.portInNumbers[1].ext'=>'',
					'portInNumberListBean.portInNumbers[2].npa'=>'',
					'portInNumberListBean.portInNumbers[2].nxx'=>'',
					'portInNumberListBean.portInNumbers[2].ext'=>'',
					'portInNumberListBean.portInNumbers[3].npa'=>'',
					'portInNumberListBean.portInNumbers[3].nxx'=>'',
					'portInNumberListBean.portInNumbers[3].ext'=>'',
					'portInNumberListBean.portInNumbers[4].npa'=>'',
					'portInNumberListBean.portInNumbers[4].nxx'=>'',
					'portInNumberListBean.portInNumbers[4].ext'=>'',
				);
				$post_data = $this->preparePostFields($post_data);
				$url = "http://shop2.sprint.com/NASApp/onlinestore/en/Action/PECAction";
				$ch = curl_init($url);
				$result = $this->curl_redir_exec($ch, null, $post_data, "http://shop2.sprint.com/NASApp/onlinestore/en/Action/PECLanding?checkAllPtnSelected=true&ptn=2039934076&wnpCheckWnpResultPageURL=%2FNASApp%2Fonlinestore%2Fen%2FAction%2FPECAction");
				curl_close($ch);
				$data = strtolower($result);
				$index = strpos($data, "not currently eligible");
				if($index){
					$carrier = "sprint";
				}
				else{
					//T-Mobile
					$ckfile = tempnam("./tmp", "tmobileCookie");
					$post_data = array(
					'__VIEWSTATE'=>'/wEPDwULLTIxMTkyNzI1MjlkZGFl1xlHUJEum1P3NU8nOIet0OOU',
					'__EVENTTARGET'=>'btnCheck',
					'__EVENTARGUMENT'=>'',
					'__EVENTVALIDATION'=>'/wEWBwLuhqmJAwLhoqXqCwLgoqXqCwLfoqXqCwLeoqXqCwLdoqXqCwK35PuABTmh+K2vzrkaW0kguQ8t8D+yBcaP',
					'txtPhone1'=>$phone,
					'txtPhone2'=>'',
					'txtPhone3'=>'',
					'txtPhone4'=>'',
					'txtPhone5'=>'',
					'hdnText'=>''
					);
					$post_data = $this->preparePostFields($post_data);
					$url = "http://www.t-mobile.com/switch/Default.aspx?referrer=";
					$url2 = "http://www.t-mobile.com/switch/Results.aspx?referrer=";
					$ch = curl_init($url);
					$result = $this->curl_redir_exec($ch, $ckfile, null, $url);
					curl_setopt($ch, CURLOPT_URL, $url);
					$result = $this->curl_redir_exec($ch, $ckfile, $post_data, $url);
					curl_close($ch);
					$ch = curl_init($url2);
					$result = $this->curl_redir_exec($ch, $ckfile, null, $url);
					curl_close($ch);
					$data = strtolower($result);
					$index = strpos($data, "already a t-mobile phone number");
					if($index){
						$carrier = "t-mobile";
					}
				}
			}
		}
		if(!empty($carrier)){
			return $carrier;
		}
		return false;
	}
}

?>
