<?php
/**
 * mailing_list_submit.php
 *
 * Created by Adam Terry.
 * Advite Business Software
 * Date: 1/07/2019
 * Time: 11:27 AM
 */
require_once '../systemAutoloader.php';

use classes\database\Queries as Queries;
use classes\security\Security as Security;

$dbq = new Queries();
$security = new Security;

// PHPMAILER NAMESPACE
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// AUTOLOAD PHPMAILER
require '../vendors/PHPMailer/vendor/autoload.php';

// IF AJAX REQUEST
if($_REQUEST['mailingList']) {
    
    $mailingList = $_REQUEST['mailingList'];
    $loyaltyProgram = $_REQUEST['loyaltyProgram'];
    $fname = $_REQUEST['fname'];
    $lname = $_REQUEST['lname'];
    $email = $_REQUEST['email'];
    $phone = $_REQUEST['phone'];
    $postcode = $_REQUEST['postcode'];
    
    
    // CHECK IF ANY FORM DATA IS MISSING. IF SO RETURN ERROR (1).
    if($mailingList == '' || $loyaltyProgram == '' || $fname == '' || $lname == '' || $email == '' || $phone == '' || $postcode == ''){
        
        // ERROR - FORM DATA MISSING
        echo 1;
        
    }elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        
        // EMAIL IS NOT VALID - THROW ERROR (2)
        echo 2;
        
    }else{
        
        // NO FORM DATA MISSING & EMAIL IS VALID.
        // CONTINUE WITH FORM PROCESSING.
        
        // SINCE WE DON'T CAPTURE THE USERS STATE ON THE SIGN UP FORM,
        // WE NOW NEED TO FIND THE USERS STATE BASED ON THEIR POSTCODE.
        // TO DO THIS WE CHECK THE USERS POSTCODE AGAINST A DATABASE TABLE CONTAINING AUSTRALIAN POSTCODES & STATES.
        
        // LOOK FOR THE USERS STATE BY SEARCHING UP THEIR POSTCODE WITHIN THE `postcodes_geo` TABLE.
        $findState = $dbq->selectConditional('postcodes_geo', 'postcode', '=', $postcode);
        
        // IF USERS POSTCODE WAS FOUND
        if(isset($findState) && $findState != false){
            
            // SET STATE VARIABLE
            $state = '';
            foreach($findState as $fs){
                
                // ASSIGN USERS STATE TO STATE VARIABLE
                $state = $fs['state'];
                
            }
            
        }else{
            
            // UNABLE TO FIND POSTCODE.
            // COLUMN WILL BE LEFT BLANK AND USER WILL BE FORCED TO UPDATE ON FIRST SIGN IN TO LOYALTY PORTAL.
            $state = '';
        }
        
        // GET MAILING LIST BASED ON MAILING LIST ID PASSED IN.
        $getMailingList = $dbq->selectConditional('loyalty_mailing_lists', 'id', '=', $mailingList);
        
        // IF MAILING LIST FOUND
        if($getMailingList != false){
            
            // SET LIST SPECIFIC VARIABLES
            $hasWelcomeMessage = '';
            $welcomeSubject = '';
            $welcomeContent = '';
            $subscribers = '';
            
            foreach($getMailingList as $gml){
                
                // ASSIGN LIST SPECIFIC VARIABLES
                $hasWelcomeMessage = $gml['hasWelcome'];
                $welcomeSubject = $gml['welcomeSubject'];
                $welcomeContent = $gml['welcomeMessage'];
                $subscribers = $gml['subscribers'];
                
            }
            
        }else{
            
            // ERROR - MAILING LIST NOT FOUND BASED ON ID PASSED IN.
            echo 4;
        }
        
        // DATE/ TIME STAMP FOR MEMBER TABLE.
        $dateCreated = date("Y-m-d H:i:s");
    
        // RANDOM NUMBER THAT WILL BE ADDED TO THE END OF MEMBER USERNAME
        // TO ENSURE A UNIQUE MEMBER ID.
        $randNum = rand(0000,9999);
    
        // CREATE MEMBER ID BY COMBINING FIRST NAME WITH THE ABOVE RANDOM NUMBER.
        $memberID = $fname.$randNum;
    
        // CREATE USERNAME - AS WE DON'T CAPTURE THIS IN THE FORM WE WILL JUST USE THE FIRST NAME.
        $username = $fname;
    
        // SET THE DEFAULT AVATAR FOR THE LOYALTY PORTAL.
        $avatar = 'avatar-0.png';
    
        // GENERATE A TEMP PASSWORD TO SEND TO THE USER.
        // CURRENT PASSWORD LENGTH SET TO 8
        function getRandomPass($len = 8) {
            
            // CREATE AN ARRAY OF LETTERS a -z & A - Z.
            $word = array_merge(range('a', 'z'), range('A', 'Z'));
            // SHUFFLE THE LETTERS WITHIN THE ARRAY.
            shuffle($word);
            // CREATE A STRING FROM THE ARRAY AND GRAB THE FIRST X CHARACTERS
            // TO CREATE THE PASSWORD.
            // RETURNS A STRING.
            return substr(implode($word), 0, $len);
            
        }
        
        // RUN THE TEMP PASSWORD FUNCTION ABOVE TO CREATE A TEMP PASSWORD AND ASSIGN THE RESULT TO $tempPass.
        $tempPass = getRandomPass();
        
        // HASH THE TEMP PASSWORD BEFORE INSERTING INTO `loyalty_portal_members` TABLE.
        $hashedPass = $security->hashString($tempPass);
        
        // All ACCOUNTS ARE ASSIGNED A UNIQUE TOKEN.
        // THIS IS A SECURITY MEASURE & IS USED AS VALIDATION ALONGSIDE THE USERS ID
        // WHEN ALLOWING THE USER TO UNSUBSCRIBE / REMOVE ACCOUNT FROM AN EMAIL LINK
        function generateUniqueToken($len = 30) {
            $word = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));
            shuffle($word);
            return substr(implode($word), 0, $len);
        }
        
        $uniqueToken = generateUniqueToken();
        
        // IF THE DEFAULT LOYALTY PROGRAM WAS REQUESTED INSTEAD OF A SPECIFIC PROGRAM.
        // FIND THE NATRAD SYSTEMS DEFAULT LOYALTY PROGRAM AS SET BY AN ADMIN.
        if($loyaltyProgram == 0){
            
            // GET THE DEFAULT LOYALTY PROGRAM FROM THE `loyalty_programs` DATABASE TABLE.
            $getDefaultLoyaltyProgram = $dbq->selectConditional('loyalty_programs', 'isDefaultProg', '=', 1);
            
            // IF THE GET DEFAULT LOYALTY PROGRAM QUERY RETURNED A RESULT.
            if(isset($getDefaultLoyaltyProgram) && $getDefaultLoyaltyProgram != false){
                
                foreach($getDefaultLoyaltyProgram as $gdlp){
                    
                    // ASSIGN THE DEFAULT LOYALTY PROGRAM ID TO PASS IN ON NEW MEMBER INSERT.
                    $loyaltyProgram = $gdlp['id'];
                    
                }
                
            }
            
        }
        
        // STORE LOCATOR
        
        // DEFAULT LATITUDE & LONGITUDE COORDS
        $lat = '0.000';
        $long = '0.000';
        
        if(isset($postcode)){
            
            // GET LATITUDE AND LONGITUDE OF STORE
            $getStoreCoordinates = $dbq->getCoordinates($postcode);
            
            // IF RESULT RETURNED
            if($getStoreCoordinates != false){
                
                foreach($getStoreCoordinates as $sc){
                    
                    $lat = $sc['latitude'];
                    $long = $sc['longitude'];
                    
                }
            }
            
        }
            
        
        // FIND NEAREST STORE @params : (latitude, longitude, search radius in kilometers)
        $nearestStore = $dbq->locateNearestStore($lat,$long,1600);
        
        // END STORE LOCATOR
        
        // SET DEFAULT STORE VARIABLES
        $linkStoreID = '0';
        if($nearestStore != false){
            foreach($nearestStore as $ns){
                $linkStoreID =  $ns['id'];
            }
        }
    
    
         // ARRAY OF DATA USED TO INSERT NEW MEMBER INTO `loyalty_portal_members` TABLE.
         // COLUMN NAME => VALUE.
        $data = array(
            'email'=>$email,
            'password'=>$hashedPass,
            'username'=>$username,
            'memberId'=>$memberID,
            'loyaltyProgram'=>$loyaltyProgram,
            'runSetup'=>0,
            'dateCreated'=>$dateCreated,
            'fName'=>$fname,
            'lName'=>$lname,
            'linkedStoreId'=>$linkStoreID,
            'phone'=>$phone,
            'state'=>$state,
            'postcode'=>$postcode,
            'avatar'=>$avatar,
            'contactAgreementSigned'=>1,
            'signupMethod'=>0,
            'uniqueToken'=>$uniqueToken
        );
    
        // BEFORE PROCEEDING WITH THE NEW MEMBER INSERT,
        // WE MUST FIRST CHECK IF THE SUPPLIED EMAIL ADDRESS IS ALREADY IN USE BY A MEMBER.
        // ** EMAIL ADDRESS IS USED AS A UNIQUE IDENTIFIER & LOGIN SO IS IMPORTANT TO AVOID DUPLICATES.
        $checkEmail = $dbq->countRows('loyalty_portal_members', 'email', '=', $email);
        
        // CHECK IF A MATCHING EMAIL ADDRESS WAS FOUND IN THE `loyalty_portal_members` TABLE.
        // ALREADY A LOYALTY MEMBER
        if($checkEmail > 0){
            
            // MATCHING EMAIL FOUND IN `loyalty_portal_members` TABLE.
            
            // WE NOW MUST CHECK IF THE MEMBER IS ALREADY SUBSCRIBED TO THIS MAILING LIST.
            
            // GET THE MEMBERS ID FROM THE `loyalty_portal_members` TABLE TO CHECK AGAINST THE SUBSCRIBERS LIST - USING EMAIL TO FIND MEMBER
            $getMemberID = $dbq->selectConditional('loyalty_portal_members', 'email', '=', $email);
            
            // IF QUERY RETURNED A RESULT
            if(isset($getMemberID) && $getMemberID != false){
                
                // SET MEMBER ID VARIABLE
                $memberID = '';
                $memberUniqueToken = '';
                
                foreach($getMemberID as $gid){
                    
                    // ASSIGN MEMBER ID VARIABLE
                    $memberID = $gid['id'];
                    $memberUniqueToken = $gid['uniqueToken'];
                    
                }
                
                // WE NEED TO CONVERT THE SUBSCRIBERS LIST TO AN ARRAY
                $subscriberIds = explode(',', $subscribers);
                
                // NOW CHECK IF THE CURRENT MEMBER ID IS FOUND IN THE $subscribersIds ARRAY (ALREADY SUBSCRIBED)
                if(in_array($memberID, $subscriberIds)){
                    
                    // MEMBER ID FOUND IN MAILING LIST SUBSCRIBERS. ALREADY SUBSCRIBED
                    // THROW ERROR (3)
                    echo 3;
                    
                }else{
                    
                    // MEMBER IS NOT SUBSCRIBED TO THIS LIST.
                    // ADD MEMBER TO LIST AND SEND EMAIL (IF ONE WAS CREATED)
                    
                    // IF THE MAILING LIST ALREADY HAS SUBSCRIBERS.
                    if($subscribers != ''){
                        
                        // ADD A COMMA TO THE MAILING LIST BEFORE ADDING THE NEW SUBSCRIBER (MEMBER ID)
                        $subscribersNew = $subscribers.','.$memberID;
                        
                    }else{
                        
                        // MAILING LIST SUBSCRIBERS IS CURRENTLY EMPTY.
                        
                        // SIMPLY ADD THE MEMBER ID TO THE SUBSCRIBERS LIST.
                        $subscribersNew = $memberID;
                        
                    }
                    
                    
                    // CREATE THE UPDATE ARRAY TO PASS IN TO UPDATE QUERY BELOW.
                    $subData = array(
                        'subscribers'=>$subscribersNew
                    );
                    
                    // UPDATE MAILING LIST SUBSCRIBERS
                    $updateMailingList = $dbq->updateRow('loyalty_mailing_lists', $subData, 'id', $mailingList);
                    
                    // CLEAR THE UPDATE DATA ARRAY
                    $subData = '';
                    
                    // ADD MEMBER TO LIST RELATIONSHIP SIGNUP DATETIME STAMP
                    
                    // DATE
                    $subDate = date('Y-m-d H:i:s');
                    
                    // DATA ARRAY TO PASS IN
                    $subTimeData = array(
                        'memberId'=>$memberID,
                        'listId'=>$mailingList,
                        'date'=>$subDate
                    );
                    
                    // ADD DATETIME STAMP TO RECORDS
                    $addTimeStamp = $dbq->insertRow('loyalty_member_list_subscribe', $subTimeData);
                    
                    // CLEAR $subTimeData ARRAY
                    $subTimeData = '';
                    
                    // PUSH TO ANY MASTER MAILING LISTS
                        
                    // GET ALL MASTER LISTS AND ADD NEW MEMBER ID TO SUBSCRIBERS
                    $getMasterLists = $dbq->selectConditional('loyalty_mailing_lists', 'masterList', '=', 1);
                    // IF RESULT
                    if($getMasterLists != false){
                        
                        $listID = '';
                        
                        // LOOP THROUGH EACH LIST AND ADD ID TO SUBSCRIBERS AND UPDATE
                        foreach($getMasterLists as $gml){
                                
                                // GET THE CURRENT LIST ID
                                $listID = $gml['id'];
                                
                                // PUSH THIS SUBSCRIBER TO ALL OTHER MASTER LISTS
                                // THAT ARE NOT THE TARGETED MAILING LIST
                                if($listID != $mailingList){
                                
                                // ADD THE MEMBER TO THE SUBSCRIBERS LIST
                                // IF SUBSCRIBERS IS EMPTY, JUST ADD THE ID.
                                // ELSE, IF SUBSCRIBERS ALREADY IN THE LIST ADD A COMMA AND THE ID
                                if($gml['subscribers'] == ''){
                                    $updatedSubs = $memberID;
                                }else{
                                    $updatedSubs = $gml['subscribers'].','.$memberID;
                                }
                                
                                
                                // CREATE AN ARRAY OF DATA TO UPDATE
                                $usData = array(
                                    'subscribers'=>$updatedSubs
                                );
                                
                                // RUN THE UPDATE
                                $updateSubsList = $dbq->updateRow('loyalty_mailing_lists', $usData, 'id', $listID);
                                
                                // CLEAR THE DATA ARRAY BEFORE LOOPING
                                $usData = '';
                                
                                // ADD SUBSCRIBE TO LIST DATE RELATIONSHIP
                                
                                // SET TIME STAMP
                                $timeStamp = date('Y-m-d H:i:s');
                                
                                // CREATE TIMESTAMP DATA ARRAY
                                $tsData = array(
                                    'memberId'=>$memberID,
                                    'listId'=>$listID,
                                    'date'=>$timeStamp
                                );
                                
                                // RUN THE TIMESTAMP INSERT
                                $addTimestamp = $dbq->insertRow('loyalty_member_list_subscribe', $tsData);
                                
                                // CLEAR THE DATA ARRAY BEFORE LOOPING
                                $tsData = '';
                                
                            }
                        }
                                
                    }
                            
                            
                    // END PUSH TO MASTER MAILING LISTS
                    
                    // IF MAILING LIST HAS WELCOME EMAIL
                    if(isset($hasWelcomeMessage) && $hasWelcomeMessage == 1){
                        
                        // SET THE EMAIL SUBJECT & BODY FROM `loyalty_mailing_lists` TABLE.
                        $subject = $welcomeSubject;
                        $body = $welcomeContent;
                        
                        // WHEN CREATING THE EMAIL FROM WITHIN NATRAD SYSTEMS THERE ARE
                        // A LIST OF TAGS THAT CAN BE APPLIED TO DYNAMICALLY PULL MEMBER DATA
                        // INTO THE EMAIL, SUCH AS FIRST NAME OR EMAIL ADDRESS.
                        // BELOW WE WILL REPLACE THESE TAGS WITH THE MEMBERS DATA.
                        
                        // REPLACE {fname} TAGS.
                        $body = Str_replace('{fname}', $fname, $body);
                        // REPLACE {lname} TAGS.
                        $body = str_replace('{lname}', $lname, $body);
                        // REPLACE the {email} TAGS.
                        $body = str_replace('{email}', $email, $body);
                        // REPLACE the {username} TAGS.
                        $body = str_replace('{username}', $username, $body);
                        // REPLACE the {memberid} TAGS.
                        $body = str_replace('{memberid}', $memberID, $body);
                        // REPLACE the {linkedstore} TAGS.
                        $body = str_replace('{linkedstore}', 'Log in to check linked store', $body);
                        // REPLACE the {password} TAGS.
                        $body = str_replace('{password}', '******<br><small>Our records indicate you are an existing loyalty member.<br>Please use your current password when signing in.</small>', $body);
                        
            
                        // EMAIL SERVER SETTINGS
                        // BEFORE SENDING AN EMAIL WE MUST FIRST GET THE REQUIRED EMAIL SERVER SETTINGS FROM NATRAD SYSTEMS.
                        
                        // SET EMAIL SERVER SETTING VARIABLES.
                        $eHost = '';
                        $eUsername = '';
                        $ePassword = '';
                        $eEncryption = '';
                        $ePort = '';
                        $eFromEmail = '';
                        $eFromName = '';
                        $eReplyEmail = '';
                        $eReplyName = '';
                        $unsubscribeLink = '';
            
                        // GET THE MEMBER PORTAL EMAIL SETTINGS.
                        $eSets = $dbq->selectConditional('settings_email_member_portal', 'id', '=', 1);
            
                        // LOOP THROUGH THE EMAIL SETTINGS RESULT AND ASSIGN VARIABLES.
                        foreach($eSets as $es){
            
                            $eHost = $es['host'];
                            $eUsername = $es['username'];
                            $ePassword = $es['password'];
                            $eEncryption = $es['encryption'];
                            $ePort = $es['port'];
                            $eFromEmail = $es['fromEmail'];
                            $eFromName = $es['fromName'];
                            $eReplyEmail = $es['replyEmail'];
                            $eReplyName = $es['replyName'];
                            $unsubscribeLink = $es['unsubscribeLink'];
                        }
                        
                        // CREATE CUSTOM OPTOUT LINK FOR THIS MEMBER
                        $unsubscribe = '<p><a href="'.$unsubscribeLink.'?lid='.$memberID.'&ml='.$mailingList.'&t='.$memberUniqueToken.'">Unsubscribe</a> from this list';
                        
                        // ATTACH THE LINK TO THE BODY
                        $body = $body.$unsubscribe;
                        
                        // CREATE NEW EMAIL AND SEND TO NEW MEMBER.
            
                        $mail = new PHPMailer(true);
                        try {
                            // MAIL SETTINGS
                            $mail->isSMTP();
                            $mail->Host = $eHost;
                            $mail->SMTPAuth = true;
                            $mail->Username = $eUsername;
                            $mail->Password = $ePassword;
                            $mail->SMTPSecure = $eEncryption;
                            $mail->Port = $ePort;
            
                            // FROM, TO & REPLY EMAIL ADDRESS SETTINGS
                            $mail->setFrom($eFromEmail, $eFromName);
                            $mail->addAddress($email);
                            $mail->addReplyTo($eReplyEmail, $eReplyName);
            
                            // EMAIL CONTENT - SUBJECT & BODY
                            $mail->isHTML(true);
                            $mail->Subject = $subject;
                            $mail->Body    = $body;
                            
                            // SEND THE WELCOME EMAIL
                            $mail->send();
                            
                        }catch (Exception $e) {
                            
                            // AN ERROR OCCURED SENDING THE EMAIL.
                            $welcomeEmailSuccess = false;
                        }
                            
                    } // END IF MAILING LIST HAS WELCOME EMAIL.
                    
                    // FORM WAS SUCCESSFULLY PROCESSED!!!
                    // RETURN SUCCESS CODE (5).
                    echo 5;
                    
                }
                
            }else{
                
                // AN ERROR OCCURED. UNABLE TO PROCESS FORM.
                // THROW ERROR CODE (0).
                echo 0;
            }
            
            
        }else{
            
            // NO MATCHING EMAIL FOUND IN `loyalty_portal_members` TABLE.
            // SUPPLIED EMAIL IS OK TO BE USED.
    
            // INSERT NEW MEMBER INTO `loyalty_portal_member` TABLE.
            $createNewMember = $dbq->insertRow('loyalty_portal_members', $data);
            
            // CHECK THE RESULT OF THE INSERT.
            
            // NEW MEMBER ADDED SUCCESSFULLY.
            // CONTINUE THE PROCESS BY ADDING THEM TO THE SPECIFIED MAILING LIST
            // AND SENDING A MAILING LIST WELCOME EMAIL (IF ONE WAS CREATED).
            if($createNewMember != false){
                
                // GET NEW MEMBER ID (USED TO ADD MEMBER TO MAILING LIST))
                $getNewMember = $dbq->selectConditional('loyalty_portal_members', 'email', '=', $email);
                
                // IF MEMBER ID FOUND
                if($getNewMember != false){
                    
                    // SET MEMBER ID VARIABLE
                    $newMemberID = '';
                    
                    foreach($getNewMember as $gnm){
                        
                        // ASSIGN MEMBER ID TO VARIABLE
                        $newMemberID = $gnm['id'];
                        
                    }
                    
                    // ADD THE ID TO THE MAILING LIST SUBSCRIBERS
                    
                    // IF THE MAILING LIST ALREADY HAS SUBSCRIBERS.
                    if($subscribers != ''){
                        
                        // ADD A COMMA TO THE MAILING LIST BEFORE ADDING THE NEW SUBSCRIBER (MEMBER ID)
                        $subscribersNew = $subscribers.','.$newMemberID;
                        
                    }else{
                        
                        // MAILING LIST SUBSCRIBERS IS CURRENTLY EMPTY.
                        
                        // SIMPLY ADD THE MEMBER ID TO THE SUBSCRIBERS LIST.
                        $subscribersNew = $newMemberID;
                        
                    }
                    
                    
                    // CREATE THE UPDATE ARRAY TO PASS IN TO UPDATE QUERY BELOW.
                    $subData = array(
                        'subscribers'=>$subscribersNew
                    );
                    
                    // UPDATE MAILING LIST SUBSCRIBERS
                    $updateMailingList = $dbq->updateRow('loyalty_mailing_lists', $subData, 'id', $mailingList);
                    
                    // ADD MEMBER TO LIST RELATIONSHIP SIGNUP DATETIME STAMP
                    
                    // DATE
                    $subDate = date('Y-m-d H:i:s');
                    
                    // DATA ARRAY TO PASS IN
                    $subTimeData = array(
                        'memberId'=>$newMemberID,
                        'listId'=>$mailingList,
                        'date'=>$subDate
                    );
                    
                    // ADD DATETIME STAMP TO RECORDS
                    $addTimeStamp = $dbq->insertRow('loyalty_member_list_subscribe', $subTimeData);
                    
                    // CLEAR $subTimeData ARRAY
                    $subTimeData = '';
                    
                    // PUSH TO MASTER MAILING LIST
                        
                    // GET ALL MASTER LISTS AND ADD NEW MEMBER ID TO SUBSCRIBERS
                    $getMasterLists = $dbq->selectConditional('loyalty_mailing_lists', 'masterList', '=', 1);
                    // IF RESULT
                    if($getMasterLists != false){
                        
                        $listID = '';
                        
                        // LOOP THROUGH EACH LIST AND ADD ID TO SUBSCRIBERS AND UPDATE
                        foreach($getMasterLists as $gml){
                                
                                // GET THE CURRENT LIST ID
                                $listID = $gml['id'];
                                
                                // PUSH THIS SUBSCRIBER TO ALL OTHER MASTER LISTS
                                // THAT ARE NOT THE TARGETED MAILING LIST
                                if($listID != $mailingList){
                                
                                // ADD THE MEMBER TO THE SUBSCRIBERS LIST
                                // IF SUBSCRIBERS IS EMPTY, JUST ADD THE ID.
                                // ELSE, IF SUBSCRIBERS ALREADY IN THE LIST ADD A COMMA AND THE ID
                                if($gml['subscribers'] == ''){
                                    $updatedSubs = $newMemberID;
                                }else{
                                    $updatedSubs = $gml['subscribers'].','.$newMemberID;
                                }
                                
                                
                                // CREATE AN ARRAY OF DATA TO UPDATE
                                $usData = array(
                                    'subscribers'=>$updatedSubs
                                );
                                
                                // RUN THE UPDATE
                                $updateSubsList = $dbq->updateRow('loyalty_mailing_lists', $usData, 'id', $listID);
                                
                                // CLEAR THE DATA ARRAY BEFORE LOOPING
                                $usData = '';
                                
                                // ADD SUBSCRIBE TO LIST DATE RELATIONSHIP
                                
                                // SET TIME STAMP
                                $timeStamp = date('Y-m-d H:i:s');
                                
                                // CREATE TIMESTAMP DATA ARRAY
                                $tsData = array(
                                    'memberId'=>$newMemberID,
                                    'listId'=>$listID,
                                    'date'=>$timeStamp
                                );
                                
                                // RUN THE TIMESTAMP INSERT
                                $addTimestamp = $dbq->insertRow('loyalty_member_list_subscribe', $tsData);
                                
                                // CLEAR THE DATA ARRAY BEFORE LOOPING
                                $tsData = '';
                                
                            }
                        }
                                
                    }
                            
                            
                    // END PUSH TO MASTER MAILING LIST
                    
                }else{
                    
                    // UNABLE TO PUSH NEW MEMBER TO MAILING LIST.
                    // THROW MAILING LIST INSERT ERROR (4)
                    echo 4;
                    
                }
        
                // IF MAILING LIST HAS WELCOME EMAIL
                if(isset($hasWelcomeMessage) && $hasWelcomeMessage == 1){
                    
                    // SET THE EMAIL SUBJECT & BODY FROM `loyalty_mailing_lists` TABLE.
                    $subject = $welcomeSubject;
                    $body = $welcomeContent;
                    
                    // WHEN CREATING THE EMAIL FROM WITHIN NATRAD SYSTEMS THERE ARE
                    // A LIST OF TAGS THAT CAN BE APPLIED TO DYNAMICALLY PULL MEMBER DATA
                    // INTO THE EMAIL, SUCH AS FIRST NAME OR EMAIL ADDRESS.
                    // BELOW WE WILL REPLACE THESE TAGS WITH THE MEMBERS DATA.
                    
                    // REPLACE {fname} TAGS.
                    $body = Str_replace('{fname}', $fname, $body);
                    // REPLACE {lname} TAGS.
                    $body = str_replace('{lname}', $lname, $body);
                    // REPLACE the {email} TAGS.
                    $body = str_replace('{email}', $email, $body);
                    // REPLACE the {username} TAGS.
                    $body = str_replace('{username}', $username, $body);
                    // REPLACE the {memberid} TAGS.
                    $body = str_replace('{memberid}', $memberID, $body);
                    // REPLACE the {linkedstore} TAGS.
                    $body = str_replace('{linkedstore}', 'Log in to link store', $body);
                    // REPLACE the {password} TAGS.
                    $body = str_replace('{password}', $tempPass, $body);
                    
        
                    // EMAIL SERVER SETTINGS
                    // BEFORE SENDING AN EMAIL WE MUST FIRST GET THE REQUIRED EMAIL SERVER SETTINGS FROM NATRAD SYSTEMS.
                    
                    // SET EMAIL SERVER SETTING VARIABLES.
                    $eHost = '';
                    $eUsername = '';
                    $ePassword = '';
                    $eEncryption = '';
                    $ePort = '';
                    $eFromEmail = '';
                    $eFromName = '';
                    $eReplyEmail = '';
                    $eReplyName = '';
                    $unsubscribeLink = '';
        
                    // GET THE MEMBER PORTAL EMAIL SETTINGS.
                    $eSets = $dbq->selectConditional('settings_email_member_portal', 'id', '=', 1);
        
                    // LOOP THROUGH THE EMAIL SETTINGS RESULT AND ASSIGN VARIABLES.
                    foreach($eSets as $es){
        
                        $eHost = $es['host'];
                        $eUsername = $es['username'];
                        $ePassword = $es['password'];
                        $eEncryption = $es['encryption'];
                        $ePort = $es['port'];
                        $eFromEmail = $es['fromEmail'];
                        $eFromName = $es['fromName'];
                        $eReplyEmail = $es['replyEmail'];
                        $eReplyName = $es['replyName'];
                        $unsubscribeLink = $es['unsubscribeLink'];
                        
                    }
                    
                    // CREATE CUSTOM OPTOUT LINK FOR THIS MEMBER
                    $unsubscribe = // CREATE CUSTOM UNSUBSCRIBE LINK
                    $unsubscribe = '<p><a href="'.$unsubscribeLink.'?lid='.$newMemberID.'&ml='.$mailingList.'&t='.$uniqueToken.'">Unsubscribe</a> from this list';
                    
                    // ATTACH THE LINK TO THE BODY
                    $body = $body.$unsubscribe;
                    
                    // CREATE NEW EMAIL AND SEND TO NEW MEMBER.
        
                    $mail = new PHPMailer(true);
                    try {
                        // MAIL SETTINGS
                        $mail->isSMTP();
                        $mail->Host = $eHost;
                        $mail->SMTPAuth = true;
                        $mail->Username = $eUsername;
                        $mail->Password = $ePassword;
                        $mail->SMTPSecure = $eEncryption;
                        $mail->Port = $ePort;
        
                        // FROM, TO & REPLY EMAIL ADDRESS SETTINGS
                        $mail->setFrom($eFromEmail, $eFromName);
                        $mail->addAddress($email);
                        $mail->addReplyTo($eReplyEmail, $eReplyName);
        
                        // EMAIL CONTENT - SUBJECT & BODY
                        $mail->isHTML(true);
                        $mail->Subject = $subject;
                        $mail->Body    = $body;
                        
                        // SEND THE WELCOME EMAIL
                        $mail->send();
                        
                    }catch (Exception $e) {
                        
                        // AN ERROR OCCURED SENDING THE EMAIL.
                        $welcomeEmailSuccess = false;
                    }
                        
                } // END IF MAILING LIST HAS WELCOME EMAIL.
                
                // FORM WAS SUCCESSFULLY PROCESSED!!!
                // RETURN SUCCESS CODE (5).
                echo 5;
                
            }else{
                
                // AN ERROR OCCURED.
                // FORM COULD NOT BE PROCCESSED. THROW ERROR (0).
                echo 0;
                
            }
            
        } // END EMAIL ADDRESS IS UNIQUE AND OK TO USE.
        
    } // END NO MISSING FORM DATA FOUND.
    
} // END IF AJAX REQUEST.
