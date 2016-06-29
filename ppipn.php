<?php
/**
 * @version 1.0
 * @package HSoMC PayPal
 * @copyright (c) 2014 Image Innovation
 * @license GPL, http://www.gnu.org/copyleft/gpl.html
 * @author Max A. Schneider
 */

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport('joomla.plugin');
jimport('joomla.log.log');

// Set this to 0 once you go live or don't require logging.
define("DEBUG", 0);

// log file location for IPN
//define("LOG_FILE", './ipn.log');
JLog::addLogger(
	array(
		'text_file' => 'plg_hsomcipn.php'
	),
	JLog::ALL,
	array('plg_hsomcipn') // only our messages
);

class plgSystemHSoMCIPN extends JPlugin
{
	function canRun() {
		if (class_exists('RSFormProHelper')) return true;
	
		$helper = JPATH_ADMINISTRATOR.DS.'components'.DS.'com_rsform'.DS.'helpers'.DS.'rsform.php';
		if (file_exists($helper))
		{
			require_once($helper);
			RSFormProHelper::readConfig();
			return true;
		}
	
		return false;
	}
	
	/**
	 * IPN handler for HSoMC donations, developed specifically for handling
	 * Ties & Tails ticket and sponsorship purchases
	 * 
	 * The handler will do the following:
	 * 1. Verify the transaction with PayPal
	 * 2. Check for validity/duplication
	 * 3. Post the payer information in the appropriate form submission
	 * 4. Send an acknowledgement email to the payer 
	 * 5. Send a notification email to the administrator 
	 *
	 * Note that the function name of the listner is matched to RSform!Pro's hook for plugins
	 * 
	 * @param  none
	 *
	 * @return none
	 *
	 * @access public
	 */
	function rsfp_f_onSwitchTasks() {
		if(DEBUG == true) {
			JLog::add('Arrived in event handler', JLog::DEBUG, 'plg_hsomcipn');
		}
		
		$plugin_task = JRequest::getVar('plugin_task');
		if(DEBUG == true) {
			JLog::add("Plugin task {$plugin_task}", JLog::DEBUG, 'plg_hsomcipn');
		}
		switch($plugin_task){
			case 'hsomc.notify':
				$this->hsomc_paypal();
				exit();
				break;
					
			default:
				break;
		}
	}
	
	function hsomc_paypal() {
		if(DEBUG == true) {
			JLog::add('Arrived in plugin task', JLog::DEBUG, 'plg_hsomcipn');
		}
		
		if (!$this->canRun())
			return;
		
		// Retrieve the original post
		$input = JFactory::getApplication()->input;
		$postData = $input->getArray(array_flip(array_keys($_POST)));
		
		// Set up the request string to send back for verification
		$req = 'cmd=_notify-validate';
		foreach ($postData as $key => $val) {
			$req .= "&$key=$val";
		}
		
		// send it
		if(DEBUG == true) {
			JLog::add("Live Mode = " . RSFormProHelper::getConfig('paypal.test'), JLog::DEBUG, 'plg_hsomcipn');
		}
		if (0 == RSFormProHelper::getConfig('paypal.test'))
			$url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
		else
			$url = 'https://www.paypal.com/cgi-bin/webscr';
		$ch = curl_init($url);
		if ($ch == FALSE) {
			die('cURL is not enabled');
		}
		
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		
		if(DEBUG == true) {
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
		}
		
		// Set TCP timeout to 30 seconds
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
		
		$res = curl_exec($ch);
		if (curl_errno($ch) != 0) // cURL error
		{
			if(DEBUG == true) {
				JLog::add("Can't connect to PayPal to validate IPN message: " . curl_error($ch), JLog::DEBUG, 'plg_hsomcipn');
			}
			curl_close($ch);
			exit;		
		} 
		else {
			// Log the entire HTTP response if debug is switched on.
			if(DEBUG == true) {
				JLog::add("HTTP request of validation request:" . curl_getinfo($ch, CURLINFO_HEADER_OUT) . " for IPN payload: {$req}", JLog::DEBUG, 'plg_hsomcipn');
				JLog::add("HTTP response of validation request: {$res}", JLog::DEBUG, 'plg_hsomcipn');
			}
			// Split response headers and payload
//			list($headers, $res) = explode("\r\n\r\n", $res, 2);
			if (DEBUG == true):
				$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$ipnStatus = substr($res, $headerSize);
			else:
				$ipnStatus = trim($res);
			endif;
			curl_close($ch);
		}

//			$response = explode('\r\n\r\n', $res);
//			$ipnStatus = trim(end($response));
	
		// Inspect IPN validation result and act accordingly		
		if (strcmp ($ipnStatus, "VERIFIED") == 0) {
//			if(DEBUG == true) {
//				JLog::add("Verified IPN: {$ipnStatus}", JLog::DEBUG, 'plg_hsomcipn');
//			}
			JLog::add("Verified IPN: {$ipnStatus}", JLog::INFO, 'plg_hsomcipn');

			// get the associated form submission
			$db = JFactory::getDBO();
			$SubmissionId = JRequest::getInt('sid');
//			if(DEBUG == true) {
//				JLog::add("Submission ID: {$SubmissionId}", JLog::DEBUG, 'plg_hsomcipn');
//			}
			JLog::add("Submission ID: {$SubmissionId}", JLog::INFO, 'plg_hsomcipn');
			$db->setQuery("SELECT * FROM #__rsform_submissions WHERE SubmissionId='".$SubmissionId."'");
			$submission = $db->loadObject();
			
			$submission->values = array();
				
			$db->setQuery("SELECT FieldName, FieldValue FROM #__rsform_submission_values WHERE SubmissionId='".$SubmissionId."'");
			$fields = $db->loadObjectList();
			foreach ($fields as $field)
				$submission->values[$field->FieldName] = $field->FieldValue;
			unset($fields);
			
			// check whether the payment_status is Completed
			
			// fields used for transaction validation
			$txn_id = JRequest::getString('txn_id');
			$payment_status = JRequest::getString('payment_status');
			$receiver_email = JRequest::getString('receiver_email');
			$payment_amount = JRequest::getFloat('mc_gross');
			$payment_currency = JRequest::getString('mc_currency');
			$code = $db->getEscaped(JRequest::getVar('code'));
			$formId = JRequest::getInt('formId');
			
			// check that txn_id has not been previously processed
			if ($payment_status == 'Completed') {
				if ($submission->values['txn_id'] == $txn_id ||
					$submission->values['txn_id'] == '') {
					if ($receiver_email != RSFormProHelper::getConfig('paypal.email')) {
						JLog::add("SID {$SubmissionId} TxnID {$txn_id} - Bad receiver email: {$receiver_email}", JLog::NOTICE, 'plg_hsomcipn');
						return;
					}
					
					// check that payment_amount/payment_currency are correct
					$sub_amount = (float)($submission->values['total']);
					$sub_currency = RSFormProHelper::getConfig('paypal.currency');
					if ($payment_amount != $sub_amount || $payment_currency != $sub_currency) {
						JLog::add("SID {$SubmissionId} TxnID {$txn_id} - Invalid payment amount: {$payment_amount} {$payment_currency}, submitted as {$sub_amount} {$sub_currency}", JLog::NOTICE, 'plg_hsomcipn' );
						return;
					}
					
					// process payment and mark item as paid.
					// update submission with txn_id
					$this::updateDb('txn_id', $db, $formId, $code);
					$this::updateDb('payment_status', $db, $formId, $code);
					$this::updateDb('payment_date', $db, $formId, $code);
					$this::updateDb('first_name', $db, $formId, $code);
					$this::updateDb('last_name', $db, $formId, $code);
					$this::updateDb('payer_email', $db, $formId, $code);
					$this::updateDb('address_street', $db, $formId, $code);
					$this::updateDb('address_city', $db, $formId, $code);
					$this::updateDb('address_state', $db, $formId, $code);
					$this::updateDb('address_zip', $db, $formId, $code);
					$this::updateDb('address_status', $db, $formId, $code);
						
					// trigger acknowledgement and notification emails
					RSFormProHelper::sendSubmissionEmails($SubmissionId);
				}
			}
			else {	// not completed (possibly pending...update form
				// update submission with txn_id
				if(DEBUG == true) {
					JLog::add("Submission ID: {$SubmissionId} Txn: {$txn_id} Status: {$payment_status}", JLog::DEBUG, 'plg_hsomcipn');
				}
				$this::updateDb('txn_id', $db, $formId, $code);
				$this::updateDb('payment_status', $db, $formId, $code);
			}
		} else if (strcmp ($ipnStatus, "INVALID") == 0) {
			// log for manual investigation
			// Add business logic here which deals with invalid IPN messages
			JLog::add("Invalid IPN: {$req}", JLog::NOTICE, 'plg_hsomcipn');
		}
	}

	// update submission with supplied argument from input
	function updateDb($argument, $db, $formId, $code) {
		$value = JRequest::getVar($argument);
		$db->setQuery("UPDATE #__rsform_submission_values sv LEFT JOIN #__rsform_submissions s ON s.SubmissionId = sv.SubmissionId SET sv.FieldValue='" . $value . "' WHERE sv.FieldName='$argument' AND sv.FormId='".$formId."' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '".$code."'");
		$db->query();
	}
}
?>