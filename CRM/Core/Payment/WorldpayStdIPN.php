<?php

/**
 * Worldpay Extension for CiviCRM - Callback Notification class
 * Original code by GreenNet Ltd and Circle Interactive extension 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

//require_once 'CRM/Core/Payment/BaseIPN.php';

class CRM_Core_Payment_WorldpayStdIPN extends CRM_Core_Payment_BaseIPN {

    protected $worldpay;
    protected static $_paymentProcessor = null;

   /**
    * Constructor
    */
    public function __construct($worldpay) {
        $this->worldpay = $worldpay;
        parent::__construct();
    }

   /**
    * Retrieve a value from the specified location
    */
    protected static function retrieve($name, $type, $location = 'POST', $abort = true) {
        static $store = null;
        $value = CRM_Utils_Request::retrieve($name, $type, $store, false, null, $location);
        if ($abort and is_null($value)) {
            $this->worldpay->log("Could not find a required entry for $name in $location");
            CRM_Utils_System::civiExit();
        }
        return $value;
    }

  /**
   * handle an actual future pay payment, this is distinct from
   * the initial futurepay agreement IPN which is sent when
   * the agreement is first made by the user
   */
  protected function handleFuturePayPayment(&$input, &$ids, &$objects) {
    $recur = &$objects['contributionRecur'];

    // make sure the invoice ids match
    // make sure the invoice is valid and matches what we have in the contribution record
    if ( $recur->invoice_id != $input['invoice'] ) {
      $this->worldpay->log("Invoice values dont match between database and IPN request");
      return false;
    }

    $now = date( 'YmdHis' );

    // fix dates that already exist
    $dates = array( 'create', 'start', 'end', 'cancel', 'modified' );
    foreach ( $dates as $date ) {
      $name = "{$date}_date";
      if ( $recur->$name ) {
        $recur->$name = CRM_Utils_Date::isoToMysql( $recur->$name );
      }
    }

    //contribution_status_id:
    //0=Completed,1=Pending,2=Cancelled,3=Overdue,4=Failed,5=InProgress
    if ($input['transStatus']=='Y') {//rawAuthMessage=Authorised
      // futurepay payment accepted
     if ($input['rawAuthMessage']!='Authorised') {
        error_log(__FILE__.":".__FUNCTION__." : Warning futurepay transStatus=".$input['transStatus']." but rawAuthMessage=".$input['rawAuthMessage']);
      }
      if (_getContributionCount($recur)>=$recur->installments) {
        // final installement
        $recur->contribution_status_id=0;//0=Completed
        $recur->end_date=$now;
      }
      else {
        $recur->contribution_status_id=5;//5=In Progress
        $recur->modified_date = $now;
      }
    }
    else if ($input['transStatus']=='N') {//rawAuthMessage=Declined
      // futurepay payment declined
      $recur->contribution_status_id=4;//4=Failed
      $recur->end_date = $now;
      if ($input['rawAuthMessage']!='Declined') {
        error_log(__FILE__.":".__FUNCTION__." : Warning futurepay transStatus=".$input['transStatus']." but rawAuthMessage=".$input['rawAuthMessage']);
      }
      $checkForEndOfAgreement=false;
    }
    else if ($input['futurePayStatusChange']=='Merchant Cancelled') {
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled by Merchant';
    }
    else if ($input['futurePayStatusChange']=='Customer Cancelled') {
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled by Donor';
    }
    else {
      $this->worldpay->log( "Unrecognized FuturePay operation. input=".print_r($input,true));
      return false;
    }
    $recur->save();

    if ($input['transStatus']=='Y') {
      // create a new contribution for this recurring contribution
      $contributionType = $objects['contributionType'];
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->domain_id = CRM_Core_Config::domainID( );
      $contribution->contact_id = $ids["contact"];
      $contribution->contribution_type_id = $contributionType->id;
      $contribution->contribution_page_id = $ids['contributionPage'];
      $contribution->contribution_recur_id = $ids['contributionRecur'];
      $contribution->receive_date = $now;
      $contribution->invoice_id =  md5( uniqid( rand( ), true ) );
      $contribution->total_amount = $input["amount"];
      $objects['contribution'] =& $contribution;

      require_once 'CRM/Core/Transaction.php';
      $transaction = new CRM_Core_Transaction();
      $this->completeTransaction($input,$ids,$objects,$transaction,true);//true=recurring
      // completeTransaction handles the transaction commit
      return true;
    }
  }

  /**
   * handle the start of a future pay agreement, this is *not*
   * an actual payment, so we must remove the contribution which
   * was created for it by CiviCRM prior to user being redirected
   * to theWorldPay servers
   */
  protected function handleFuturepayAgreement(&$input, &$ids, &$objects) {
    $recur = $objects['contributionRecur'];
    $contribution =& $objects['contribution'];


    // remove the contribution which was added since
    // this is a new futurepay notification rather than
    // an actual payment
    $res=CRM_Contribute_BAO_Contribution::deleteContribution($contribution->id);
    if (!$res) {
      CRM_Core_Error::debug_log_message( "Problem encountered while deleting unwanted Contribution id=".$contribution->id." res=".print_r($res,true));
      error_log(__FILE__.":".__FUNCTION__." : Problem encountered while deleting unwanted Contribution id=".$contribution->id." res=".print_r($res,true));
        return false;
    }

    $now = date( 'YmdHis' );

    if ($input["transStatus"]=="Y") {
      // futurepay agreemement made

      // update and save recur details
      $recur->create_date=$now;
      $recur->contribution_status_id=1;//pending ... no contribution yet made
      $recur->save();
    }
    else if ($input["transStatus"]=="C") {
      // futurepay agreement cancelled
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled by Peace X Peace';
    }
    else {
      CRM_Core_Error::debug_log_message("Unrecognized transStatus for single payment=".$input["transStatus"]);
      error_log(__FILE__.":".__FUNCTION__." : Unrecognized transStatus for single payment=".$input["transStatus"]);
      return false;
    }

    return true;
  }

  /**
   * handle a single one off payment
   */
  protected function handleSinglePayment(&$input, &$ids, &$objects) {
    $contribution =& $objects['contribution'];
    require_once 'CRM/Core/Transaction.php';
    $transaction = new CRM_Core_Transaction();
    if ($input["transStatus"]=="Y") {

      $this->worldpay->log(__FILE__.":".__FUNCTION__." : now completing transaction");

      $this->completeTransaction($input,$ids,$objects,$transaction,false);// false= not recurring
    }
    else if ($input["transStatus"]=="C") {
      $this->cancelled($objects,$trasaction);
    }
    else {
      $this->worldpay->log("Unrecognized transStatus for single payment=".$input["transStatus"]);
      return false;
    }
    // completeTransaction handles the transaction commit
    return true;
  }


  /**
   *  handle WorldPay payment response
   */
  public function main($isFuturePay) {

    $this->worldpay->log(__FILE__.":".__FUNCTION__." : handling WorldPay payment response IPN");

    $objects = $ids = $input = array( );

    $component = self::retrieve('MC_module','String','POST',true);
    $contact   = self::retrieve('MC_contact_id','Integer','POST',true);
    $paymentProcessorID = self::retrieve('processor_id','Integer','REQUEST',true);

    $input["component"] = $component;

    $this->getInput($input,$ids);
    $this->worldpay->log(__FILE__.":".__FUNCTION__." : component is $component");

    if (!$isFuturePay) {
      // not a future payment notication but may be a future payment
      // agreement being made...

      // get ids
      $ids["contact"] = self::retrieve('MC_contact_id','String','POST',true);
      $ids["contribution"] = self::retrieve('MC_contribution_id','String','POST',true);
      if ($component=='event') {
        $ids["event"] = self::retrieve('MC_event_id','String','POST',true);
        $ids["participant"] = self::retrieve('MC_participant_id','String','POST',true);
      }
      else {
        // contribution optional id's
        $ids['membership'] = self::retrieve('MC_membership_id','String','POST',false);
        $ids['contributionRecur'] = self::retrieve( 'MC_contribution_recur_id', 'Integer', 'GET', false );
        $ids['contributionPage']  = self::retrieve( 'MC_contribution_page_id' , 'Integer', 'GET', false );
      }

      if (is_numeric($input["futurePayId"])) {
        // this is a futurepay agreement being made
        // so store the ids and do nothing more here
        // since no money has actually been transferred yet
        // ...watch futurepay repsonse for such transfers
        self::storeFuturePayIds($component,$input["futurePayId"],$ids);
        return;
      }
    }
    else {
      if (!is_numeric($input["futurePayId"])) {
        CRM_Core_Error::debug_log_message("futurePayId is missing for recurring payment.");
        error_log(__FILE__.":".__FUNCTION__." : futurePayId is missing for recurring payment. input=".print_r($input,true));
        exit();
      }
      self::retrieveFuturePayIds($component,$input["futurePayId"],$ids);
      if (!$ids) {
        CRM_Core_Error::debug_log_message("Some sort of error occured retrieving ids for futurePayId=$futurePayId");
        error_log(__FILE__.":".__FUNCTION__." : Some sort of error occured retrieving ids for futurePayId=$futurePayId");
        exit();
      }
    }

    // validateData also loads the obects
    if (!$this->validateData($input,$ids,$objects,TRUE,$paymentProcessorID)) {
      error_log(__FILE__.":".__LINE__.": validate data failed, input=".print_r($input,true)." ids=".print_r($ids,true));
      return false;
    }

    //error_log(__FILE__.":".__FUNCTION__." : objects=".print_r($objects,true));

    self::$_paymentProcessor =& $objects['paymentProcessor'];
    if ($isFuturePay) {
      // future pay payment notification
      return $this->handleFuturepayPayment($input,$ids,$objects);
    }
    else if (is_numeric($input["futurePayId"])) {
      // future pay payment initial agreement set up
      // appears like a single payment but no money changes hands
      return $this->handleFuturepayAgreement($input,$ids,$objects);
    }
    else {
      // single payment
      $this->worldpay->log(__FILE__.":".__FUNCTION__." : now processing single payment");
      return $this->handleSinglePayment($input,$ids,$objects);
    }
  }

  ///////////////////////

  protected function getInput( &$input, &$ids ) {
    if ( ! $this->getBillingID( $ids ) ) {
      return false;
    }

  // Parameters generated by puchase token:
    $input['cartId'] = self::retrieve('cartId','String','POST',true);
    $input['amount'] = self::retrieve('amount','String','POST',true);

    $test = self::retrieve('testMode','Integer','POST',false);
    if ($test == '100') {
      $input['is_test'] = 1;
    }
    $input['currency'] = self::retrieve('currency','String','POST',true);
    // authMode = A
    $splitName = self::splitName(self::retrieve('name','String','POST',true ));

    if (isset($splitName["first_name"])) {
      $input["first_name"] = $splitName["first_name"];
    }
    if (isset($splitName["middle_name"])) {
      $input["middle_name"] = $splitName["middle_name"];
    }
    if (isset($splitName["first_name"])) {
      $input["last_name"] = $splitName["last_name"];
    }
    $billingID = $ids['billing'];
    $lookup = array( "street_address-{$billingID}" => 'address',
                     "postal_code-{$billingID}"    => 'postcode',
                     "country-{$billingID}"        => 'country',
                     "phone-{$billingID}"          => 'phone',
                     "fax-{$billingID}"            => 'fax',
                     "email-{$billingID}"          => 'email' );
    foreach ( $lookup as $name => $paypalName ) {
      $value = self::retrieve( $paypalName, 'String', 'POST', false );
      $input[$name] = $value ? $value : null;
    }

  // Payment response parameters:
    // transId is not present if this is a cancelled payment
    $input['trxn_id']      = self::retrieve('transId','Integer','POST',false);
    $input['transStatus']  = self::retrieve('transStatus','String','POST',true);
    $dateMillis            = self::retrieve('transTime','Integer','POST',true);
    $input["trxn_date"]    = date('YmdHis', $dateMillis / 1000);
    $input['authAmount']   = self::retrieve('authAmount','Money','POST',true);
    $input['authCurrency'] = self::retrieve('authCurrency','String','POST',true);
    // unused: authAmountString
    $input['rawAuthMessage'] = self::retrieve('rawAuthMessage','String','POST',true);
    // unused: rawAuthCode = A:Authorised
    // unused: callbackPW
    $input['cardType'] = self::retrieve('cardType','String','POST',true);
    $input['AVS'] = self::retrieve('AVS','String','POST',true);
    // unused: ipAddress
    // unused: charenc

  // Optional FuturePay parameters:
    $input['futurePayId'] = self::retrieve('futurePayId','Integer','POST',false);
    $input['futurePayStatusChange'] = self::retrieve('futurePayStatusChange','String','POST',false);
  }

  /**
   * store futurepay ids when a future pay agreement is first
   * made and recorded via IPN
   */
  protected function storeFuturePayIds($component,$futurePayId,&$ids) {
    if ($component=='event') { // sanity check
      CRM_Core_Error::debug_log_message("Error while storing ids,FuturePay should not be used for event payments.");
      error_log(__FILE__.":".__FUNCTION__." : Error while storing ids, FuturePay should not be used for event payments.");
      return false;
    }
    $setIds = array ();
    $setIds["contact_id"] = $ids["contact"];
    $setIds["contribution"] = $ids["contribution"];
    $setIds["contribution_recur_id"]=$ids["contribution_recur"];
    $setIds["contribution_page_id"]=$ids["contribution_page"];
    if (isset($ids["membership"])) {
      $setIds["membership_id"]=$ids["membership"];
    }

    $fieldsNames = implode(",",array_keys($setIds));
    $fieldValues = implode(",",array_values($setIds));

    $query = "INSERT INTO worldpay_futurepay_ids($fieldNames) ";
    $query.= "VALUES($fieldValues)";
    $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );
    $res = ( $dao ? true : false);

    //error_log(__FILE__.":".__FUNCTION__." : storing futurepay details : sql=$query ");
    return $res;
  }

  /**
   * retrieve futurepay ids when a futurepay payment notification
   * is retrieved via IPN
   */
  protected function retrieveFuturePayIds($component,$futurePayId,&$ids) {
    if ($component=='event') { // sanity check
      CRM_Core_Error::debug_log_message("Error while retrieving ids,FuturePay should not be used for event payments.");
      error_log(__FILE__.":".__FUNCTION__." : Error while retrieving ids, FuturePay should not be used for event payments.");
      return false;
    }
    $query = "SELECT * FROM worldpay_futurepay_ids WHERE futurepay_id=$futurePayId";
    $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );
    if (!$dao->fetch()) {
      error_log(__FILE__.":".__FUNCTION__." : failed to retrieve futurepay details : sql=$query");
      return false;
    }
    $ids["contact"]=$dao->contact_id;
    $ids["contribution"]=$dao->contribution_id;
    $ids["contribution_recur"]=$dao->contribution_recur_id;
    $ids["contribution_page"]=$dao->contribution_page_id;
    if (is_numeric($dao->membership_id)) {
      $ids["membership"]=$dao->membership_id;
    }
    //error_log(__FILE__.":".__FUNCTION__." : retrieved futurepay ids=".print_r($ids,true));
    return true;
  }

  /**
   * splits the name since worldpay names are single field but
   * civicrm names have firstname middlename lastname
   */
  protected static function splitName($fullName) {
    $name = array();
    $splitstr = explode(" ",$fullName);
    if (sizeof($splitstr)==1) {
      $name["last_name"] = $fullName;
    }
    if (sizeof($splitstr)==2) {
      $name["last_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    if (sizeof($splitstr)==3) {
      $name["last_name"] = $fullName[2];
      $name["middle_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    else {
      $startLast = strlen($fullName[0]) + 1 + strlen($fullName[1]) + 1;
      $name["last_name"] = substr($fullName,$startLast);
      $name["middle_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    return $name;
  }

   /**
   * THIS FUNCTION REMOVED IN CIVICRM 5.56.0, WE ADDED FOR RESOLVING ERROR 
   *
   * Validate incoming data.
   *
   * This function is intended to ensure that incoming data matches
   * It provides a form of pseudo-authentication - by checking the calling fn already knows
   * the correct contact id & contribution id (this can be problematic when that has changed in
   * the meantime for transactions that are delayed & contacts are merged in-between. e.g
   * Paypal allows you to resend Instant Payment Notifications if you, for example, moved site
   * and didn't update your IPN URL.
   *
   * @param array $input
   *   Interpreted values from the values returned through the IPN.
   * @param array $ids
   *   More interpreted values (ids) from the values returned through the IPN.
   * @param array $objects
   *   An empty array that will be populated with loaded object.
   * @param bool $required
   *   Boolean Return FALSE if the relevant objects don't exist.
   * @param int $paymentProcessorID
   *   Id of the payment processor ID in use.
   *
   * @deprecated
   *
   * @return bool
   */
  public function validateData($input, &$ids, &$objects, $required = TRUE, $paymentProcessorID = NULL) {
    CRM_Core_Error::deprecatedFunctionWarning('unused');
    // Check if the contribution exists
    // make sure contribution exists and is valid
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $ids['contribution'];
    if (!$contribution->find(TRUE)) {
      throw new CRM_Core_Exception('Failure: Could not find contribution record for ' . (int) $contribution->id, NULL, ['context' => "Could not find contribution record: {$contribution->id} in IPN request: " . print_r($input, TRUE)]);
    }

    // make sure contact exists and is valid
    // use the contact id from the contribution record as the id in the IPN may not be valid anymore.
    $contact = new CRM_Contact_BAO_Contact();
    $contact->id = $contribution->contact_id;
    $contact->find(TRUE);
    if ($contact->id != $ids['contact']) {
      // If the ids do not match then it is possible the contact id in the IPN has been merged into another contact which is why we use the contact_id from the contribution
      CRM_Core_Error::debug_log_message("Contact ID in IPN {$ids['contact']} not found but contact_id found in contribution {$contribution->contact_id} used instead");
      echo "WARNING: Could not find contact record: {$ids['contact']}<p>";
      $ids['contact'] = $contribution->contact_id;
    }

    if (!empty($ids['contributionRecur'])) {
      $contributionRecur = new CRM_Contribute_BAO_ContributionRecur();
      $contributionRecur->id = $ids['contributionRecur'];
      if (!$contributionRecur->find(TRUE)) {
        CRM_Core_Error::debug_log_message("Could not find contribution recur record: {$ids['ContributionRecur']} in IPN request: " . print_r($input, TRUE));
        echo "Failure: Could not find contribution recur record: {$ids['ContributionRecur']}<p>";
        return FALSE;
      }
    }

    $objects['contact'] = &$contact;
    $objects['contribution'] = &$contribution;

    // CRM-19478: handle oddity when p=null is set in place of contribution page ID,
    if (!empty($ids['contributionPage']) && !is_numeric($ids['contributionPage'])) {
      // We don't need to worry if about removing contribution page id as it will be set later in
      //  CRM_Contribute_BAO_Contribution::loadRelatedObjects(..) using $objects['contribution']->contribution_page_id
      unset($ids['contributionPage']);
    }

    if (!$this->loadObjects($input, $ids, $objects, $required, $paymentProcessorID)) {
      return FALSE;
    }
    return TRUE;
  }
  
    /**
   * Load objects related to contribution.
   *
   * @deprecated
   *
   * @input array information from Payment processor
   *
   * @param array $input
   * @param array $ids
   * @param array $objects
   * @param bool $required
   * @param int $paymentProcessorID
   *
   * @return bool|array
   * @throws \CRM_Core_Exception
   */
  public function loadObjects($input, &$ids, &$objects, $required, $paymentProcessorID) {
    CRM_Core_Error::deprecatedFunctionWarning('use api methods in ipn');
    $contribution = &$objects['contribution'];
    $ids['paymentProcessor'] = $paymentProcessorID;
    $success = $contribution->loadRelatedObjects($input, $ids);
    $objects = array_merge($objects, $contribution->_relatedObjects);
    return $success;
  }

};

};
