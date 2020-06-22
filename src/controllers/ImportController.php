<?php
/**
 * SS User Import Export plugin for Craft CMS 3.x
 *
 * This plugin help new user import using csv and export user into the csv file.
 *
 * @link      http://www.systemseeders.com/
 * @copyright Copyright (c) 2020 ssplugin
 */

namespace ssplugin\ssuserimportexport\controllers;

use ssplugin\ssuserimportexport\SsUserImportExport;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\elements\User;
use yii\web\NotFoundHttpException;
use Craft;
use craft\web\Controller;

use craft\commerce\services\Emails as Email;
use craft\commerce\events\EmailEvent;
use craft\commerce\db\Table;
use craft\db\Query;
use craft\mail\Message;
use craft\web\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use GuzzleHttp;


/**
 * Import Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    ssplugin
 * @package   SsUserImportExport
 * @since     1.0.0
 */
class ImportController extends Controller
{    
    protected $allowAnonymous = ['index', 'import-user','user-import', 'element-map', 'test'];

    public function actionIndex()
    {
        $this->renderTemplate('ss-user-import-export/tab/import');
    }

    public function actionElementMap()
    {        
        $this->renderTemplate('ss-user-import-export/tab/element-map');
    }
    
    public function actionImportUser()
    {        
        
        $request = Craft::$app->getRequest()->getBodyParams();    
        //Make sure activated craft pro version.
        if( Craft::$app->getEdition() === Craft::Solo ){            
            Craft::$app->session->setError('Craft Pro is required.');
            return $this->redirect(UrlHelper::cpUrl('ss-user-import-export/import'));          
        }

        if( isset( $_FILES['file'] ) ) {            
            $file_name = $_FILES['file']['name'];          
            $file_tmp  = $_FILES['file']['tmp_name'];            
            $ext       = pathinfo($file_name, PATHINFO_EXTENSION);
            if( $ext == 'csv' ) {
                $str = fopen($file_tmp, 'r');
                $header = NULL;
                $data = [];
                while ( ( $row = fgetcsv( $str, 1000, ',' ) ) !== FALSE)
                {
                    
                    if( !$header ) {
                        $header = $row;
                    } else {  
                        if (count($header) == count($row)) {
                            $data[] = array_combine($header, $row);
                        }
                    }
                }                
                fclose($str);
                $plugin   = SsUserImportExport::getInstance();
                $settings = ['response_data'  => $data, 'response_header' => $header, 'lastUpFile' => $file_name];
                $url = UrlHelper::cpUrl('ss-user-import-export/import/element-map');
                Craft::$app->getPlugins()->savePluginSettings( $plugin, $settings );                
                Craft::$app->session->setNotice('File uploaded successfully.');
                return $this->redirect($url);               
            } else {
                Craft::$app->session->setError('Please choose a CSV file..');
            }                              
        }
    }

    public function actionUserImport(){       
        $request = Craft::$app->getRequest()->getBodyParams();
        $settings = craft::$app->plugins->getPlugin('ss-user-import-export')->getSettings();
        
        if( !empty($request) ){
            if( empty($request['field']['username']) || empty($request['field']['email']) || empty($request['field']['usergroup']) ){
                $url = UrlHelper::cpUrl('ss-user-import-export/import/element-map');
                Craft::$app->session->setError('Select Username, email and Group. These fields are required.');
                return $this->redirect($url);                
            }       
            $usercount = 0;                         
            foreach ( $settings->response_data as $key => $value ) {               
                $fields = [];
                foreach ( $request['field'] as $k => $val ) {
                    if( $val != '' ) {
                        $fields[$k] = $value[$val];
                    }else{
                        $fields[$k] = '';
                    }
                }                
                if( !empty( $fields['username'] ) && !empty($fields['email']) ){
                    if (filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                        $isUsernameExist = User::find()->username($fields['username'])->status(['active','suspended', 'pending', 'locked'])->one();
                        $isEmailExist = User::find()->email($fields['email'])->status(['active','suspended', 'pending', 'locked'])->one();

                        if( empty($isUsernameExist) && empty($isEmailExist)) {
                            $group = '';
                            if( $fields['usergroup'] == 'admin') {
                                $group = 'admin';
                            } else {
                                $groups = Craft::$app->userGroups->getGroupByHandle( $fields['usergroup'] );
                                if( !empty($groups)){
                                    $group = $groups['id'];
                                } else {
                                    $projectConfig = Craft::$app->getSystemSettings()->getSettings('users');
                                    if( !empty($projectConfig['defaultGroup']) ){
                                        $defaultGroup = Craft::$app->userGroups->getGroupByUid($projectConfig['defaultGroup']);
                                        $group = $defaultGroup['id'];
                                    }
                                }
                            }
                            if( isset($group) && !empty($group)){                                                    
                                if( !empty($fields)){
                                    $user = new User();                    
                                    $user->username = $fields['username'];
                                    $user->firstName= $fields["firstName"];
                                    $user->lastName = $fields["lastName"];
                                    $user->email    = $fields["email"];
                                    if(!empty($fields["password"]) ){
                                        $user->newPassword  = trim($fields["password"]);    
                                    }
                                    if( $group == 'admin'){
                                        $user->admin  = true;
                                    }
                                    switch (strtolower($fields['userstatus'])) {
                                        case 'active':
                                        case '1':
                                            $user->pending  = false;
                                            break;
                                        case 'pending':
                                        case '0':
                                            $user->pending  = true;
                                            break;
                                        case 'suspended':                        
                                            $user->suspended  = true;
                                            break;
                                        default:
                                            $user->pending  = true;
                                            break;
                                    }
                                    
                                    if( isset($request['userfield']) && !empty($request['userfield']) ){
                                        $userfield = [];
                                        foreach ($request['userfield'] as $k => $v) {
                                            if( !empty($v) ) {
                                                $userfield[$k] = $value[$v];
                                            }
                                        }
                                        if( !empty($userfield) ) {
                                            $user->setFieldValues( $userfield );
                                        }
                                    }                                   
                                    $isSaveUser = Craft::$app->getElements()->saveElement($user, false);
                                    if( $isSaveUser ){
                                        $usercount++;                                    
                                        if( $group != 'admin'){
                                            Craft::$app->users->assignUserToGroups($user->id, [$group]);
                                        }
                                        if($request['sendmail'] == 'yes'){
                                            if( strtolower($fields['userstatus']) == 'active' || strtolower($fields['userstatus']) == '1'){
                                                Craft::$app->getUsers()->sendActivationEmail($user);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }                    
            $url = UrlHelper::cpUrl('ss-user-import-export/import');
            if( $usercount > 0 ){
                Craft::$app->session->setNotice("User added successfully.");
            }
            return $this->redirect($url);
        }
    }

    public function actionTest(){
        $sec = Craft::$app->pluginStore->getToken();

        // $client = new GuzzleHttp\Client();
        // $response = $client->request(
        //   'GET',
        //   'https://api.craftcms.com/v1/plugin-licenses',
        //   [
        //     'auth' => [
        //       'SystemSeeders',
        //       'z0fkam80xabs0juloweikfe4c9lns4k56nr3f2dc'
        //     ],
        //   ]
        // );
        
       

        // $resp = $response->getBody()->getContents();

        echo '<pre>';
        print_r(json_decode($sec));
        exit(); 
        // return $resp;
        
    }
}   
