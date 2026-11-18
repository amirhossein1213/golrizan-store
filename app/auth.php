<?php
declare(strict_types=1);

function admin_user(): ?array
{
    return is_array($_SESSION['admin_user']??null)?$_SESSION['admin_user']:null;
}

function admin_required(): void
{
    $user=admin_user();$timeout=max(15,min(1440,(int)setting('admin_session_timeout_minutes','120')))*60;$lastActivity=(int)($_SESSION['admin_last_activity']??0);
    if($user&&$lastActivity>0&&$lastActivity<time()-$timeout){admin_logout('session_timeout');$user=null;}
    if($user&&((int)($_SESSION['admin_identity_checked_at']??0)<time()-300)){
        $stmt=db()->prepare('SELECT a.id,a.username,a.full_name,a.role_id,a.is_active,a.last_password_change_at,r.is_active role_active FROM admins a LEFT JOIN admin_roles r ON r.id=a.role_id WHERE a.id=?');$stmt->execute([(int)$user['id']]);$fresh=$stmt->fetch();$passwordChanged=$fresh&&!empty($fresh['last_password_change_at'])&&strtotime((string)$fresh['last_password_change_at'])>(int)($_SESSION['admin_authenticated_at']??0);
        if(!$fresh||!(int)$fresh['is_active']||($fresh['role_id']!==null&&!(int)$fresh['role_active'])||$passwordChanged){admin_logout($passwordChanged?'password_changed':'account_disabled');$user=null;}
        else{$_SESSION['admin_user']=['id'=>(int)$fresh['id'],'username'=>$fresh['username'],'full_name'=>$fresh['full_name'],'role_id'=>$fresh['role_id']!==null?(int)$fresh['role_id']:null];$user=$_SESSION['admin_user'];$_SESSION['admin_identity_checked_at']=time();}
    }
    if($user){$_SESSION['admin_last_activity']=time();}
    if(!headers_sent()){header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');}
    if(!$user){$next=urlencode($_SERVER['REQUEST_URI']??app_url('admin/'));redirect(app_url('admin/login.php?next='.$next));}
    admin_enforce_route();admin_register_request_audit();
}

function admin_finish_login(array $admin): void
{
    session_regenerate_id(true);unset($_SESSION['login_attempts'],$_SESSION['login_locked_until'],$_SESSION['_admin_2fa_pending']);
    $_SESSION['admin_user']=['id'=>(int)$admin['id'],'username'=>$admin['username'],'full_name'=>$admin['full_name'],'role_id'=>$admin['role_id']!==null?(int)$admin['role_id']:null];
    $_SESSION['admin_last_activity']=time();$_SESSION['admin_identity_checked_at']=time();$_SESSION['admin_authenticated_at']=time();
    db()->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$admin['id']]);
    admin_audit_event('login_success','admin',(int)$admin['id']);
}

function admin_login(string $username,string $password): bool|string
{
    $stmt=db()->prepare('SELECT a.id,a.username,a.full_name,a.password_hash,a.is_active,a.role_id,a.two_factor_enabled,r.is_active role_active FROM admins a LEFT JOIN admin_roles r ON r.id=a.role_id WHERE a.username=? LIMIT 1');$stmt->execute([$username]);$admin=$stmt->fetch();
    $dummyHash='$2y$12$PS0hm12oShftqZzu9yzPVuee929JHYxv3jbhb.r4UT5feI5c6T1WO';$valid=password_verify($password,$admin['password_hash']??$dummyHash);
    if(!$admin||!(int)$admin['is_active']||($admin['role_id']!==null&&!(int)$admin['role_active'])||!$valid){admin_audit_event('login_failed',null,null,['username'=>$username]);return false;}
    if((int)$admin['two_factor_enabled']===1){session_regenerate_id(true);$_SESSION['_admin_2fa_pending']=['id'=>(int)$admin['id'],'created_at'=>time()];return 'two_factor';}
    admin_finish_login($admin);return true;
}

function admin_pending_two_factor(): ?array
{
    $pending=$_SESSION['_admin_2fa_pending']??null;
    if(!is_array($pending)||(int)($pending['id']??0)<1||(int)($pending['created_at']??0)<time()-300){unset($_SESSION['_admin_2fa_pending']);return null;}
    return $pending;
}

function admin_complete_two_factor(string $code): bool
{
    $pending=admin_pending_two_factor();if(!$pending)return false;
    $stmt=db()->prepare('SELECT a.id,a.username,a.full_name,a.is_active,a.role_id,a.two_factor_enabled,a.two_factor_secret_enc,r.is_active role_active FROM admins a LEFT JOIN admin_roles r ON r.id=a.role_id WHERE a.id=?');$stmt->execute([(int)$pending['id']]);$admin=$stmt->fetch();if(!$admin||!(int)$admin['is_active']||!(int)$admin['two_factor_enabled']||($admin['role_id']!==null&&!(int)$admin['role_active'])){unset($_SESSION['_admin_2fa_pending']);return false;}
    $secret=reveal_secret((string)$admin['two_factor_secret_enc']);
    if(!admin_totp_verify($secret,$code)&&!admin_use_recovery_code((int)$admin['id'],$code)){admin_audit_event('two_factor_failed','admin',(int)$admin['id']);return false;}
    admin_finish_login($admin);return true;
}

function admin_logout(string $reason='manual'): void
{
    $user=admin_user();if($user)admin_audit_event('logout','admin',(int)$user['id'],['reason'=>$reason]);
    unset($_SESSION['admin_user'],$_SESSION['admin_last_activity'],$_SESSION['admin_identity_checked_at'],$_SESSION['admin_authenticated_at'],$_SESSION['_admin_2fa_pending'],$_SESSION['_csrf']);session_regenerate_id(true);
}
