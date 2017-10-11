<?php
/**
 * Weixin Notification Module
 * @author miles.jin
 */

class ActionWx extends ActionNotification
{
    public static function Init()
    {
        $aParams = array
        (
            "category" => "core/cmdb,application",
            "key_type" => "autoincrement",
            "name_attcode" => "name",
            "state_attcode" => "",
            "reconc_keys" => array('name'),
            "db_table" => "priv_action_wx",
            "db_key_field" => "id",
            "db_finalclass_field" => "",
            "display_template" => "",
        );
        MetaModel::Init_Params($aParams);
        MetaModel::Init_InheritAttributes();

        MetaModel::Init_AddAttribute(new AttributeString("test_recipient", array("allowed_values"=>null, "sql"=>"test_recipient", "default_value"=>"", "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeOQL("target", array("allowed_values"=>null, "sql"=>"target", "default_value"=>null, "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeTemplateText("message", array("allowed_values"=>null, "sql"=>"message", "default_value"=>null, "is_null_allowed"=>false, "depends_on"=>array())));
        // Display lists
        MetaModel::Init_SetZListItems('details', array('name', 'description', 'status', 'test_recipient', 'target', 'message', 'trigger_list'));
        MetaModel::Init_SetZListItems('list', array('name', 'status', 'target'));
        // Search criteria
        MetaModel::Init_SetZListItems('standard_search', array('name','description', 'status'));
//		MetaModel::Init_SetZListItems('advanced_search', array('name'));
    }

    // count the recipients found
    protected $m_iRecipients;

    // Errors management : not that simple because we need that function to be
    // executed in the background, while making sure that any issue would be reported clearly
    protected $m_aWxErrors; //array of strings explaining the issue

    private $sModuleName = 'action-wx-notifications';

    // returns a the list of target phone numbers as an array, or a detailed error description
    protected function FindRecipients($sRecipAttCode, $aArgs)
    {
        $sOQL = $this->Get($sRecipAttCode);
        if (strlen($sOQL) == '') return array();
        try
        {
            $oSearch = DBObjectSearch::FromOQL($sOQL);
            $oSearch->AllowAllData();
        }
        catch (OQLException $e)
        {
            $this->m_aWxErrors[] = "query syntax error for recipient '$sRecipAttCode'";
            return $e->getMessage();
        }

        $sClass = $oSearch->GetClass();
        $sWxAttCode = Metamodel::GetModuleSetting('wx-config', 'target_attcode', 'openid');

        if (!MetaModel::IsValidAttCode($sClass, $sWxAttCode, true))
        {
            $this->m_aWxErrors[] = "wrong target for recipient '$sRecipAttCode'";
            return "The objects of the class '$sClass' do not have any wx attribute";
        }

        $oSet = new DBObjectSet($oSearch, array() /* order */, $aArgs);
        $aRecipients = array();
        while ($oObj = $oSet->Fetch())
        {
            $sTarget = trim($oObj->Get($sWxAttCode));
            if (strlen($sTarget) > 0)
            {
                $aRecipients[] = $sTarget;
                $this->m_iRecipients++;
            }
        }
        return $aRecipients;
    }
    
    public function DoExecute($oTrigger, $aContextArgs)
    {
        if (MetaModel::GetModuleSetting('wx-config', 'enable_wx', true) !== true) return;
        if (MetaModel::IsLogEnabledNotification())
        {
            // TODO: Create own log class
            $oLog = new EventNotificationWx();
            if ($this->IsBeingTested())
            {
                $oLog->Set('message', 'TEST - Notification sent ('.$this->Get('test_recipient').')');
            }
            else
            {
                $oLog->Set('message', 'Notification pending');
            }
            $oLog->Set('userinfo', UserRights::GetUser());
            $oLog->Set('trigger_id', $oTrigger->GetKey());
            $oLog->Set('action_id', $this->GetKey());
            $oLog->Set('object_id', $aContextArgs['this->object()']->GetKey());
            // Must be inserted now so that it gets a valid id that will make the link
            // between an eventual asynchronous task (queued) and the log
            $oLog->DBInsertNoReload();
        }
        else
        {
            $oLog = null;
        }

        try
        {
            $sRes = $this->_DoExecute($oTrigger, $aContextArgs, $oLog);

            if ($this->IsBeingTested())
            {
                $sPrefix = 'TEST ('.$this->Get('test_recipient').') - ';
            }
            else
            {
                $sPrefix = '';
            }
            $oLog->Set('message', $sPrefix.$sRes);

        }
        catch (Exception $e)
        {
            if ($oLog)
            {
                $oLog->Set('message', 'Error: '.$e->getMessage());
            }
        }
        if ($oLog)
        {
            $oLog->DBUpdate();
        }
    }

    protected function _DoExecute($oTrigger, $aContextArgs, &$oLog)
    {
        $sPreviousUrlMaker = ApplicationContext::SetUrlMakerClass();
        try
        {
            $this->m_iRecipients = 0;
            $this->m_aWxErrors = array();
            $bRes = false; // until we do succeed in sending the email

            // Determine recicipients
            $aTargets = $this->FindRecipients('target', $aContextArgs);
            $sMessage = $this->ApplyParams($this->Get('message'), $aContextArgs);
            $oObj = $aContextArgs['this->object()'];
        }
        catch(Exception $e)
        {
            ApplicationContext::SetUrlMakerClass($sPreviousUrlMaker);
            throw $e;
        }
        ApplicationContext::SetUrlMakerClass($sPreviousUrlMaker);

        if (!is_null($oLog))
        {
            // Note: we have to secure this because those values are calculated
            // inside the try statement, and we would like to keep track of as
            // many data as we could while some variables may still be undefined
            if (isset($aTargets)) $oLog->Set('to', implode(', ', $aTargets));
            if (isset($sMessage)) $oLog->Set('body', $sMessage);
        }
        $appid = MetaModel::GetModuleSetting('wx-config', 'appid', '');
        $appsecret = MetaModel::GetModuleSetting('wx-config', 'secret', '');

        if ($appid == '' || $appsecret == '')
        {
            $this->m_aWxErrors[] = "Wx appid or secret is empty in WellProcess config file.";
            return 'Wx appid or secret is empty in WellProcess config file.';
        }

        if ($this->IsBeingTested())
        {
            $sMessage = 'TEST['.$sMessage.']';
            $aTargets = [$this->Get('test_recipient')];
        }
        
        if (empty($this->m_aWxErrors))
        {
            if ($this->m_iRecipients == 0)
            {
                return 'No recipient';
            }
            else
            {
                $token_url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$appid.'&secret='.$appsecret;

                $token = json_decode(file_get_contents($token_url));

                if (isset($token->errcode))
                {
                    return $token->errmsg;

                }

                $send_message_url = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$token->access_token;

                $sReturnMessage = '';

                foreach ($aTargets as $sTarget)
                {
                    $json = str_replace('$openid', $sTarget, $sMessage);

                    $aData = $this->http_post_json($send_message_url, $json);

                    if (isset($aData[0],$aData[1]) && $aData[0] == '200')
                    {
                        $aMessage = json_decode($aData[1]);

                        if (isset($aMessage->errcode, $aMessage->errmsg))
                        {
                            $sReturnMessage .= $sTarget.'('.$aMessage->errmsg.')';
                        }
                        else
                        {
                            $sReturnMessage .= $sTarget.'(unknown error)';
                        }
                    }
                    else
                    {
                        $sReturnMessage .= $sTarget.'('.$aData[0].')';
                    }
                }

                return $sReturnMessage;
            }
        }
        else
        {
            if (is_array($this->m_aWxErrors) && count($this->m_aWxErrors) > 0)
            {
                $sError = implode(', ', $this->m_aWxErrors);
            }
            else
            {
                $sError = 'Unknown reason';
            }
            return 'Notification was not sent: '.$sError;
        }
    }

    protected function http_post_json($url, $jsonStr)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($jsonStr)
            )
        );
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return array($httpCode, $response);
    }

    protected function ApplyParams($sInput, $aParams)
    {
        // Declare magic parameters
        $aParams['APP_URL'] = utils::GetAbsoluteUrlAppRoot();
        $aParams['MODULES_URL'] = utils::GetAbsoluteUrlModulesRoot();

        $aSearches = array();
        $aReplacements = array();

        foreach($aParams as $sSearch => $replace)
        {
            // Some environment parameters are objects, we just need scalars
            if (is_object($replace))
            {
                $iPos = strpos($sSearch, '->object()');
                if ($iPos !== false)
                {
                    // Expand the parameters for the object
                    $sName = substr($sSearch, 0, $iPos);
                    if (preg_match_all('/\\$'.$sName.'->([^\\$]+)\\$/', $sInput, $aMatches))
                    {
                        foreach($aMatches[1] as $sPlaceholderAttCode)
                        {
                            try
                            {
                                $sReplacement = $replace->GetForTemplate($sPlaceholderAttCode);
                                if ($sReplacement !== null)
                                {
                                    $aReplacements[] = $this->escapeJsonString($sReplacement);
                                    $aSearches[] = '$'.$sName.'->'.$sPlaceholderAttCode.'$';
                                }
                            }
                            catch(Exception $e)
                            {
                                // No replacement will occur
                            }
                        }
                    }
                }
                else
                {
                    continue; // Ignore this non-scalar value
                }
            }

            $aSearches[] = '$'.$sSearch.'$';
            $aReplacements[] = $this->escapeJsonString((string) $replace);
        }

        return str_replace($aSearches, $aReplacements, $sInput);
    }

    protected function escapeJsonString($value) {
        $escapers = array("\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c");
        $replacements = array("\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f",
            "\\b");
        $result = str_replace($escapers, $replacements, $value);
        return $result;
    }
}

class EventNotificationWx extends EventNotification
{
    public static function Init()
    {
        $aParams = array
        (
            "category" => "core/cmdb,view_in_gui",
            "key_type" => "autoincrement",
            "name_attcode" => "",
            "state_attcode" => "",
            "reconc_keys" => array(),
            "db_table" => "priv_event_wx",
            "db_key_field" => "id",
            "db_finalclass_field" => "",
            "display_template" => "",
            "order_by_default" => array('date' => false)
        );
        MetaModel::Init_Params($aParams);
        MetaModel::Init_InheritAttributes();
        MetaModel::Init_AddAttribute(new AttributeText("to", array("allowed_values"=>null, "sql"=>"to", "default_value"=>null, "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeText("body", array("allowed_values"=>null, "sql"=>"body", "default_value"=>null, "is_null_allowed"=>true, "depends_on"=>array())));

        // Display lists
        MetaModel::Init_SetZListItems('details', array('date', 'userinfo', 'message', 'trigger_id', 'action_id', 'object_id', 'to', 'body')); // Attributes to be displayed for the complete details
        MetaModel::Init_SetZListItems('list', array('date', 'message', 'to')); // Attributes to be displayed for a list

        // Search criteria
//		MetaModel::Init_SetZListItems('standard_search', array('name')); // Criteria of the std search form
//		MetaModel::Init_SetZListItems('advanced_search', array('name')); // Criteria of the advanced search form
    }

}
/*

 secret is empty

*/