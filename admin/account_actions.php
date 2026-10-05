<?php
require_once __DIR__.'/../shared/bootstrap.php';
require_authenticated_user(true);require_permission('rbac.manage',true);
header('Cache-Control: no-store');
require_once __DIR__.'/AccountAdministration.php';
$service=new AccountAdministration(authorization_connection());
try{
    $action=request_method()==='POST'?($_POST['action']??''):($_GET['action']??'');
    if($action==='get'){require_http_method(['GET']);$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Select a valid account.');json_response(true,'Account loaded.',['user'=>$service->get($id)]);}
    require_http_method(['POST']);require_valid_csrf_token();
    if($action==='save')json_response(true,'Account saved.',$service->save($_POST,(int)current_user_id()));
    if($action==='permissions'){$service->savePermissions($_POST,(int)current_user_id());json_response(true,'Role permissions saved.');}
    json_response(false,'Unknown action.',[],400);
}catch(InvalidArgumentException $e){json_response(false,$e->getMessage(),[],422);}
catch(mysqli_sql_exception $e){log_application_error('Account administration failed',$e);json_response(false,$e->getCode()===1062?'Username or email already exists.':'Unable to save account changes.',[], $e->getCode()===1062?409:500);}
