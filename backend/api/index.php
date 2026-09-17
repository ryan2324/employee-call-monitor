<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
// Allow the manager web app to authenticate against a separate API origin.
session_name((string)$config['app']['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (bool)$config['app']['cookie_secure'],
    'httponly' => true,
    'samesite' => 'None',
]);
session_start();
date_default_timezone_set((string)$config['timezone']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && hash_equals((string)$config['app']['allowed_origin'], $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function body(): array { $v=json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($v)?$v:[]; }
function out(array $v,int $status=200): never { http_response_code($status); echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function manager(): void { if (empty($_SESSION['manager_id'])) out(['error'=>'Unauthorized'],401); }
function ensureSettingsTable(PDO $p): void {
    static $done=false; if($done) return;
    $p->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('auto_idle_warning_minutes','5') ON CONFLICT (setting_key) DO NOTHING");
    $p->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('auto_idle_warning_enabled','0') ON CONFLICT (setting_key) DO NOTHING");
    $p->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('screen_wake_enabled','0') ON CONFLICT (setting_key) DO NOTHING");
    $p->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('screen_wake_interval_seconds','15') ON CONFLICT (setting_key) DO NOTHING");
    $done=true;
}
function autoIdleWarning(PDO $p,int $employeeId,string $deviceId,string $state):void{
    if($state!=='READY'||!$employeeId||!$deviceId)return;

    // Read settings before taking any lock. The ON/OFF switch is explicit;
    // minutes is retained when the feature is turned OFF.
    ensureSettingsTable($p);
    $q=$p->prepare("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('auto_idle_warning_enabled','auto_idle_warning_minutes')");
    $q->execute();
    $settings=[];
    foreach($q->fetchAll() as $row){$settings[(string)$row['setting_key']] = (string)$row['setting_value'];}
    $enabled=array_key_exists('auto_idle_warning_enabled',$settings)
        ? ((string)$settings['auto_idle_warning_enabled']==='1')
        : ((int)($settings['auto_idle_warning_minutes']??0)>0);
    $minutes=max(0,min(240,(int)($settings['auto_idle_warning_minutes']??0)));
    if(!$enabled||$minutes<=0)return;

    // Use a PostgreSQL session advisory lock instead of a PDO transaction here.
    // This prevents two rapid idle-check requests from creating duplicate warnings
    // without leaving the connection in PostgreSQL's 25P02 failed-transaction state.
    $lockKey=substr(hash('sha256',$deviceId),0,16);
    $locked=false;
    try{
        $q=$p->prepare('SELECT pg_advisory_lock(hashtext(:k))');
        $q->execute(['k'=>$lockKey]);
        $locked=true;

        $q=$p->prepare("SELECT state_changed_at,call_state FROM devices WHERE device_id=:d AND employee_id=:e AND active=1 LIMIT 1");
        $q->execute(['d'=>$deviceId,'e'=>$employeeId]);
        $r=$q->fetch();
        if(!$r||$r['call_state']!=='READY'||empty($r['state_changed_at']))return;

        $changedTs=strtotime((string)$r['state_changed_at'].' UTC');
        if($changedTs===false||time()-$changedTs<($minutes*60))return;

        $q=$p->prepare("SELECT id FROM warning_commands WHERE device_id=:d AND acknowledged_at IS NULL ORDER BY id LIMIT 1");
        $q->execute(['d'=>$deviceId]);
        if($q->fetchColumn())return;

        $msg='You have been idle for '.$minutes.' minute'.($minutes===1?'':'s').'. Please resume calling now.';
        $q=$p->prepare("INSERT INTO warning_commands(employee_id,device_id,command_type,message) VALUES(:e,:d,'AUTO_IDLE_WARNING',:m)");
        $q->execute(['e'=>$employeeId,'d'=>$deviceId,'m'=>$msg]);
    }catch(Throwable $e){
        // Log the actual failing statement context; do not try to continue a failed transaction.
        error_log('AUTO IDLE WARNING DB ERROR: '.get_class($e).' | '.$e->getMessage());
        throw $e;
    }finally{
        if($locked){
            try{$p->prepare('SELECT pg_advisory_unlock(hashtext(:k))')->execute(['k'=>$lockKey]);}
            catch(Throwable $unlockError){error_log('AUTO IDLE WARNING UNLOCK ERROR: '.$unlockError->getMessage());}
        }
    }
}

function db(): PDO {
    global $config; static $pdo=null; if($pdo instanceof PDO) return $pdo;
    $pdo=new PDO($config['db']['dsn'],$config['db']['user'],$config['db']['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false
    ]); return $pdo;
}
function hasColumn(PDO $p,string $table,string $column): bool {
    static $cache=[]; $key=$table.'.'.$column; if(array_key_exists($key,$cache)) return $cache[$key];
    $q=$p->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=:t AND column_name=:c');
    $q->execute(['t'=>$table,'c'=>$column]); return $cache[$key]=((int)$q->fetchColumn()>0);
}
function audit(string $action,array $details=[]):void{ try{ $q=db()->prepare('INSERT INTO audit_logs(manager_id,action,details,ip_address) VALUES(:m,:a,:d,:ip)'); $q->execute(['m'=>$_SESSION['manager_id']??null,'a'=>$action,'d'=>$details?json_encode($details):null,'ip'=>$_SERVER['REMOTE_ADDR']??null]); }catch(Throwable $e){} }
function statusFor(array $d):string{
    global $config;
    if(empty($d['last_seen'])) return 'OFFLINE';

    // PostgreSQL stores last_seen/state_changed_at as TIMESTAMP WITHOUT TIME ZONE
    // in UTC. PHP runs in Asia/Manila, so strtotime() without an explicit
    // timezone incorrectly interprets these database timestamps as Manila time
    // and makes an actively heartbeating phone appear offline.
    $seen=strtotime((string)$d['last_seen'].' UTC');
    if($seen===false || time()-$seen>(int)$config['app']['heartbeat_timeout_seconds']) return 'OFFLINE';

    if(($d['call_state']??'READY')==='IN_CALL') return 'IN_CALL';

    $changed=!empty($d['state_changed_at'])
        ? strtotime((string)$d['state_changed_at'].' UTC')
        : $seen;

    // Manager table status is intentionally a 1-minute inactivity threshold.
    // This keeps READY -> IDLE independent from a longer auto-warning setting.
    $idleThreshold=60;
    return ($changed!==false && time()-$changed >= $idleThreshold)
        ? 'IDLE'
        : 'READY';
}
function dtLocalToDb(?string $iso):?string{
    if(!$iso) return null; $ts=strtotime($iso); return $ts===false?null:date('Y-m-d H:i:s',$ts);
}
function normalizeNumber(?string $n):?string{
    if($n===null) return null; $n=trim($n); if($n==='') return null; return mb_substr($n,0,80);
}
function updateDailyStats(PDO $p,int $employeeId,string $date,int $duration,string $result):void{
    $success=$result==='CONNECTED'?1:0; $fail=$result==='NOT_CONNECTED'?1:0;
    $cols=['employee_id','stat_date','calls_today','talk_seconds_today'];
    $vals=[':e',':d','1',':dur'];
    $updates=['calls_today=daily_employee_stats.calls_today+EXCLUDED.calls_today','talk_seconds_today=daily_employee_stats.talk_seconds_today+EXCLUDED.talk_seconds_today'];
    if(hasColumn($p,'daily_employee_stats','successful_calls_today')){ $cols[]='successful_calls_today';$vals[]=':s';$updates[]='successful_calls_today=daily_employee_stats.successful_calls_today+EXCLUDED.successful_calls_today'; }
    if(hasColumn($p,'daily_employee_stats','unsuccessful_calls_today')){ $cols[]='unsuccessful_calls_today';$vals[]=':f';$updates[]='unsuccessful_calls_today=daily_employee_stats.unsuccessful_calls_today+EXCLUDED.unsuccessful_calls_today'; }
    $sql='INSERT INTO daily_employee_stats('.implode(',',$cols).') VALUES('.implode(',',$vals).') ON CONFLICT (employee_id,stat_date) DO UPDATE SET '.implode(',',$updates);
    $p->prepare($sql)->execute(['e'=>$employeeId,'d'=>$date,'s'=>$success,'f'=>$fail,'dur'=>$duration]);
}


$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$path='/' . trim(preg_replace('#/+#','/',$uri),'/');
// InfinityFree-safe route fallback: also accept index.php?route=dashboard.
if (isset($_GET['route']) && $_GET['route'] !== '') {
    $route='/' . trim((string)$_GET['route'],'/');
    $path='/api' . $route;
}
// Backward compatibility for the previous JS bug that omitted the slash.
if (str_starts_with($path,'/api') && !str_starts_with($path,'/api/')) {
    $path='/api/' . ltrim(substr($path,4),'/');
}
$method=$_SERVER['REQUEST_METHOD']??'GET';

try {
    // Connect lazily so the health endpoint can report database errors instead of returning a blank/500 response.
    $p = db();
    if(($path==='/api' || $path==='/api/' || $path==='/api/index.php' || $path==='/api/health') && $method==='GET'){
        try{$p->query('SELECT 1');$tables=[];foreach(['managers','employees','devices','call_history','daily_employee_stats'] as $t){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=:t");$q->execute(['t'=>$t]);$tables[$t]=((int)$q->fetchColumn()>0);}
            out(['ok'=>true,'service'=>'employee-call-monitor-api','database'=>true,'tables'=>$tables,'php'=>PHP_VERSION,'api_base'=>$config['api_base_url'],'server_time'=>gmdate('c')]);
        }catch(Throwable $e){out(['ok'=>false,'database'=>false,'error'=>'Database connection failed'],500);}
    }
    if($path==='/api/auth/login' && $method==='POST'){
        $b=body();$q=$p->prepare('SELECT id,email,password_hash,full_name,role FROM managers WHERE email=:e AND active=1 LIMIT 1');$q->execute(['e'=>trim((string)($b['email']??''))]);$u=$q->fetch();
        if(!$u||!password_verify((string)($b['password']??''),$u['password_hash']))out(['error'=>'Invalid credentials'],401);
        session_regenerate_id(true);$_SESSION['manager_id']=(int)$u['id'];audit('LOGIN');out(['authenticated'=>true,'manager'=>['id'=>(int)$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']]]);
    }
    if($path==='/api/auth/logout' && $method==='POST'){audit('LOGOUT');$_SESSION=[];session_destroy();out(['ok'=>true]);}

    if($path==='/api/settings/auto-idle-warning' && $method==='GET'){
        manager(); ensureSettingsTable($p);
        $q=$p->prepare("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('auto_idle_warning_enabled','auto_idle_warning_minutes')");$q->execute();$settings=[];foreach($q->fetchAll() as $r)$settings[(string)$r['setting_key']]=(string)$r['setting_value'];
        $enabled=((string)($settings['auto_idle_warning_enabled']??'0')==='1');$minutes=max(1,min(240,(int)($settings['auto_idle_warning_minutes']??5)));
        out(['enabled'=>$enabled,'minutes'=>$minutes]);
    }
    if($path==='/api/settings/auto-idle-warning' && $method==='POST'){
        manager(); ensureSettingsTable($p); $b=body();$enabled=!empty($b['enabled']);$minutes=max(1,min(240,(int)($b['minutes']??5)));
        $q=$p->prepare("INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES('auto_idle_warning_enabled',:v,NOW()) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value,updated_at=NOW()");$q->execute(['v'=>$enabled?'1':'0']);
        $q=$p->prepare("INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES('auto_idle_warning_minutes',:v,NOW()) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value,updated_at=NOW()");$q->execute(['v'=>(string)$minutes]);
        audit('SET_AUTO_IDLE_WARNING',['enabled'=>$enabled,'minutes'=>$minutes]);out(['ok'=>true,'enabled'=>$enabled,'minutes'=>$minutes]);
    }
    if($path==='/api/settings/screen-wake' && $method==='GET'){
        manager(); ensureSettingsTable($p); $q=$p->prepare("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('screen_wake_enabled','screen_wake_interval_seconds')");$q->execute();$settings=[];foreach($q->fetchAll() as $r)$settings[(string)$r['setting_key']]=(string)$r['setting_value'];
        $enabled=((string)($settings['screen_wake_enabled']??'0')==='1');$seconds=max(5,min(3600,(int)($settings['screen_wake_interval_seconds']??15)));
        out(['enabled'=>$enabled,'interval_seconds'=>$seconds]);
    }
    if($path==='/api/settings/screen-wake' && $method==='POST'){
        manager(); ensureSettingsTable($p); $b=body();$enabled=!empty($b['enabled']);$seconds=max(5,min(3600,(int)($b['interval_seconds']??15)));
        $q=$p->prepare("INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES('screen_wake_enabled',:v,NOW()) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value,updated_at=NOW()");$q->execute(['v'=>$enabled?'1':'0']);
        $q=$p->prepare("INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES('screen_wake_interval_seconds',:v,NOW()) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value,updated_at=NOW()");$q->execute(['v'=>(string)$seconds]);
        audit('SET_SCREEN_WAKE',['enabled'=>$enabled,'interval_seconds'=>$seconds]);out(['ok'=>true,'enabled'=>$enabled,'interval_seconds'=>$seconds]);
    }

    if($path==='/api/join/token' && $method==='POST'){
        manager();$token=bin2hex(random_bytes(16));$exp=time()+(int)$config['app']['join_token_minutes']*60;
        $q=$p->prepare('INSERT INTO join_tokens(token,expires_at,created_by) VALUES(:t,:exp,:m)');$q->execute(['t'=>$token,'exp'=>gmdate('Y-m-d H:i:s',$exp),'m'=>$_SESSION['manager_id']]);audit('CREATE_JOIN_TOKEN');
        out(['token'=>$token,'expires_at'=>gmdate('c',$exp)]);
    }

    if($path==='/api/employee/screen-wake-config' && $method==='GET'){
        $device=trim((string)($_GET['device_id']??''));
        if(!$device)out(['joined'=>false,'error'=>'Device ID required'],422);
        $q=$p->prepare('SELECT id FROM devices WHERE device_id=:d AND active=1 LIMIT 1');$q->execute(['d'=>$device]);
        if(!$q->fetchColumn())out(['joined'=>false,'error'=>'Device is not registered on the server'],404);
        ensureSettingsTable($p);
        $q=$p->prepare("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('screen_wake_enabled','screen_wake_interval_seconds')");$q->execute();$settings=[];foreach($q->fetchAll() as $r)$settings[(string)$r['setting_key']]=(string)$r['setting_value'];
        $enabled=((string)($settings['screen_wake_enabled']??'0')==='1');$seconds=max(5,min(3600,(int)($settings['screen_wake_interval_seconds']??15)));
        out(['joined'=>true,'enabled'=>$enabled,'interval_seconds'=>$seconds]);
    }

    if($path==='/api/employee/join' && $method==='POST'){
        $b=body();
        $token=trim((string)($b['token']??''));
        $name=trim((string)($b['name']??''));
        $device=trim((string)($b['device_id']??''));
        $model=trim((string)($b['model']??''));

        if(!$token||!$name||!$device)out([
            'joined'=>false,
            'error'=>'Name, token and device ID are required',
            'missing'=>array_values(array_filter([
                'token'=>$token?'':'token',
                'name'=>$name?'':'name',
                'device_id'=>$device?'':'device_id'
            ]))
        ],422);

        /*
         * IMPORTANT: Keep employee joining OUT of a long PDO transaction.
         * Neon/PgBouncer can expose a connection with an aborted transaction
         * state (25P02). The previous implementation could then fail at the
         * token-claim statement even though the token itself was valid.
         *
         * We first validate the token, then atomically claim it in autocommit
         * mode. Employee/device writes are also autocommit statements. This
         * makes every statement independent and prevents one failed statement
         * from poisoning the following statement.
         */
        try {
            // Explicitly clear any stale failed transaction state.
            try { $p->exec('ROLLBACK'); } catch(Throwable $ignored) {}

            $q=$p->prepare('SELECT id, used_at, expires_at FROM join_tokens WHERE token=:t LIMIT 1');
            $q->execute(['t'=>$token]);
            $jt=$q->fetch();

            if(!$jt) out(['joined'=>false,'error'=>'Join code was not found. Generate a new QR code.'],400);
            if($jt['used_at']!==null) out(['joined'=>false,'error'=>'This join code has already been used. Generate a new QR code.'],400);

            $q=$p->prepare("SELECT id FROM join_tokens WHERE id=:id AND expires_at > (NOW() AT TIME ZONE 'UTC') LIMIT 1");
            $q->execute(['id'=>$jt['id']]);
            if(!$q->fetchColumn()) out(['joined'=>false,'error'=>'This join code has expired. Generate a new QR code.'],400);

        } catch(Throwable $e) {
            // If PostgreSQL reports 25P02, clear the aborted transaction and
            // expose the actual validation error on the next request.
            try { $p->exec('ROLLBACK'); } catch(Throwable $ignored) {}
            $info=$e instanceof PDOException ? $e->errorInfo : null;
            error_log(
                'EMPLOYEE JOIN VALIDATION ERROR: '.get_class($e).
                ' | '.$e->getMessage().
                ' | sqlstate='.($info[0]??'').
                ' | detail='.($info[2]??'').
                ' | token_prefix='.substr($token,0,8).
                ' | device='.substr($device,0,32)
            );
            out([
                'joined'=>false,
                'error'=>'Could not validate the join code. Check the Render log.',
                'request_id'=>substr(bin2hex(random_bytes(6)),0,12)
            ],500);
        }

        $step='claim join token';

        try {
            // Autocommit atomic claim. No BEGIN/COMMIT is used here.
            $q=$p->prepare("UPDATE join_tokens
                SET used_at=NOW()
                WHERE id=:id
                  AND used_at IS NULL
                  AND expires_at > (NOW() AT TIME ZONE 'UTC')
                RETURNING id");
            $q->execute(['id'=>$jt['id']]);
            $claimed=$q->fetchColumn();

            if(!$claimed) {
                out(['joined'=>false,'error'=>'This join code is no longer available. Generate a new QR code.'],400);
            }

            $step='find device';
            $q=$p->prepare('SELECT id,employee_id FROM devices WHERE device_id=:d LIMIT 1');
            $q->execute(['d'=>$device]);
            $old=$q->fetch();

            if($old){
                $eid=(int)$old['employee_id'];

                $step='update employee';
                $p->prepare('UPDATE employees SET name=:n,active=1 WHERE id=:e')
                    ->execute(['n'=>$name,'e'=>$eid]);

                $step='update device';
                $p->prepare("UPDATE devices
                    SET model=:model,
                        last_seen=NOW(),
                        call_state='READY',
                        state_changed_at=NOW(),
                        active=1
                    WHERE id=:id")
                    ->execute(['model'=>$model?:null,'id'=>$old['id']]);

            } else {
                $step='insert employee';
                $q=$p->prepare('INSERT INTO employees(name) VALUES(:n) RETURNING id');
                $q->execute(['n'=>$name]);
                $eid=(int)$q->fetchColumn();

                if($eid<=0) throw new RuntimeException('Employee record could not be created.');

                $step='insert device';
                $q=$p->prepare("INSERT INTO devices(
                    employee_id,device_id,model,last_seen,call_state,state_changed_at
                ) VALUES(
                    :e,:d,:m,NOW(),'READY',NOW()
                )");
                $q->execute(['e'=>$eid,'d'=>$device,'m'=>$model?:null]);
            }

            audit('EMPLOYEE_JOIN',['employee_id'=>$eid,'device_id'=>$device]);
            out([
                'joined'=>true,
                'employee_id'=>$eid,
                'device_id'=>$device,
                'message'=>'Phone connected successfully'
            ]);

        } catch(Throwable $e) {
            // No application transaction is active here. Still clear any
            // server-side aborted state so this connection is healthy for the
            // next request.
            try { $p->exec('ROLLBACK'); } catch(Throwable $ignored) {}

            $info=$e instanceof PDOException ? $e->errorInfo : null;
            $sqlstate=(string)($info[0]??$e->getCode()??'');

            error_log(
                'EMPLOYEE JOIN ERROR: '.get_class($e).
                ' | step='.$step.
                ' | '.$e->getMessage().
                ' | sqlstate='.$sqlstate.
                ' | detail='.($info[2]??'').
                ' | token_prefix='.substr($token,0,8).
                ' | device='.substr($device,0,32)
            );

            $msg=$e instanceof RuntimeException
                ? $e->getMessage()
                : 'Could not register device. Join step: '.$step.'. Check the Render log.';

            out([
                'joined'=>false,
                'error'=>$msg,
                'request_id'=>substr(bin2hex(random_bytes(6)),0,12)
            ],400);
        }
    }

    if($path==='/api/employee/status' && $method==='GET'){
        $device=trim((string)($_GET['device_id']??''));if(!$device)out(['joined'=>false,'error'=>'Device ID required'],422);
        $q=$p->prepare('SELECT e.id employee_id,e.name,e.department,d.device_id,d.model,d.battery_level,d.last_seen,d.call_state,d.state_changed_at,d.active FROM devices d JOIN employees e ON e.id=d.employee_id WHERE d.device_id=:d LIMIT 1');$q->execute(['d'=>$device]);$r=$q->fetch();
        if(!$r||!(int)$r['active'])out(['joined'=>false,'error'=>'Device is not registered on the server'],404);$r['computed_status']=statusFor($r);out(['joined'=>true,'employee'=>$r]);
    }

    if($path==='/api/employee/heartbeat' && $method==='POST'){
        $b=body();$device=trim((string)($b['device_id']??''));$state=strtoupper(trim((string)($b['call_state']??'READY')));$battery=array_key_exists('battery_level',$b)?max(0,min(100,(int)$b['battery_level'])):null;
        if(!$device)out(['ok'=>false,'error'=>'Device ID required'],422);if(!in_array($state,['READY','IN_CALL'],true))$state='READY';
        $q=$p->prepare("UPDATE devices SET last_seen=NOW(),battery_level=COALESCE(:b,battery_level),call_state=:s,state_changed_at=CASE WHEN call_state<>:s2 THEN NOW() ELSE state_changed_at END,call_started_at=CASE WHEN :s3='IN_CALL' AND call_state<>'IN_CALL' THEN NOW() WHEN :s4<>'IN_CALL' THEN NULL ELSE call_started_at END WHERE device_id=:d AND active=1 RETURNING employee_id,state_changed_at");
        $q->execute(['b'=>$battery,'s'=>$state,'s2'=>$state,'s3'=>$state,'s4'=>$state,'d'=>$device]);$r=$q->fetch();
        if(!$r)out(['ok'=>false,'joined'=>false,'error'=>'Device is not registered on the server'],404);
        out(['ok'=>true,'joined'=>true,'employee_id'=>(int)$r['employee_id'],'state_changed_at'=>$r['state_changed_at'],'server_time'=>gmdate('c')]);
    }

    if($path==='/api/employee/idle-check' && $method==='POST'){
        $b=body();$device=trim((string)($b['device_id']??''));if(!$device)out(['ok'=>false,'error'=>'Device ID required'],422);
        $q=$p->prepare('SELECT employee_id,call_state FROM devices WHERE device_id=:d AND active=1 LIMIT 1');$q->execute(['d'=>$device]);$r=$q->fetch();
        if(!$r)out(['ok'=>false,'joined'=>false,'error'=>'Device is not registered on the server'],404);
        try{
            autoIdleWarning($p,(int)$r['employee_id'],$device,(string)$r['call_state']);
        }catch(Throwable $e){
            $info=$e instanceof PDOException ? $e->errorInfo : null;
            error_log('AUTO IDLE WARNING ERROR: '.get_class($e).' | '.$e->getMessage().' | sqlstate='.($info[0]??'').' | detail='.($info[2]??''));
        }
        out(['ok'=>true,'joined'=>true,'server_time'=>gmdate('c')]);
    }

    if($path==='/api/employee/calls/sync' && $method==='POST'){
        $b=body();$device=trim((string)($b['device_id']??''));$calls=$b['calls']??[];
        if(!$device||!is_array($calls))out(['ok'=>false,'error'=>'Device ID and calls are required'],422);
        $q=$p->prepare('SELECT employee_id FROM devices WHERE device_id=:d AND active=1 LIMIT 1');$q->execute(['d'=>$device]);$eid=(int)$q->fetchColumn();if(!$eid)out(['ok'=>false,'error'=>'Device is not registered on the server'],404);
        $insert=$p->prepare("INSERT INTO call_history(employee_id,device_id,android_call_log_id,direction,contact_number,result,started_at,ended_at,duration_seconds)
            VALUES(:e,:d,:cid,:dir,:num,:result,:start,:end,:dur)
            ON CONFLICT (device_id,android_call_log_id) DO NOTHING");
        $update=$p->prepare("UPDATE call_history SET contact_number=:num,result=:result,ended_at=:end,duration_seconds=:dur WHERE device_id=:d AND android_call_log_id=:cid");
        $added=0;$updated=0;
        foreach(array_slice($calls,0,100) as $c){
            $cid=(int)($c['call_log_id']??0);$start=dtLocalToDb($c['started_at']??null);$end=dtLocalToDb($c['ended_at']??null);$dur=max(0,(int)($c['duration_seconds']??0));$dir=strtoupper((string)($c['direction']??'OUTGOING'));$result=$dur>0?'CONNECTED':'NOT_CONNECTED';
            if(!$cid||!$start)continue;if(!in_array($dir,['INCOMING','OUTGOING','UNKNOWN'],true))$dir='UNKNOWN';
            $params=['e'=>$eid,'d'=>$device,'cid'=>$cid,'dir'=>$dir,'num'=>normalizeNumber($c['contact_number']??null),'result'=>$result,'start'=>$start,'end'=>$end,'dur'=>$dur];
            $insert->execute($params);
            if($insert->rowCount()===1){$added++;updateDailyStats($p,$eid,substr($start,0,10),$dur,$result);}else{$update->execute(['num'=>$params['num'],'result'=>$result,'end'=>$end,'dur'=>$dur,'d'=>$device,'cid'=>$cid]);$updated++;}
        }
        out(['ok'=>true,'added'=>$added,'updated'=>$updated,'received'=>count($calls)]);
    }

    if($path==='/api/employee/dashboard' && $method==='GET'){
        $device=trim((string)($_GET['device_id']??''));if(!$device)out(['error'=>'Device ID required'],422);
        $q=$p->prepare("SELECT e.id employee_id,e.name,e.department,d.device_id,d.model,d.battery_level,d.last_seen,d.call_state,d.state_changed_at FROM devices d JOIN employees e ON e.id=d.employee_id WHERE d.device_id=:d AND d.active=1 LIMIT 1");$q->execute(['d'=>$device]);$e=$q->fetch();if(!$e)out(['error'=>'Device is not registered'],404);
        $e['status']=statusFor($e);
        $successExpr=hasColumn($p,'daily_employee_stats','successful_calls_today')?'COALESCE(SUM(successful_calls_today),0)':'0'; $failExpr=hasColumn($p,'daily_employee_stats','unsuccessful_calls_today')?'COALESCE(SUM(unsuccessful_calls_today),0)':'0'; $q=$p->prepare("SELECT COALESCE(SUM(calls_today),0) total_calls,$successExpr successful_calls,$failExpr unsuccessful_calls,COALESCE(SUM(talk_seconds_today),0) total_duration FROM daily_employee_stats WHERE employee_id=:e AND stat_date=CURRENT_DATE");$q->execute(['e'=>$e['employee_id']]);$stats=$q->fetch()?:[];
        $q=$p->prepare("SELECT id,contact_number,result,direction,started_at,ended_at,duration_seconds FROM call_history WHERE employee_id=:e ORDER BY started_at DESC LIMIT 50");$q->execute(['e'=>$e['employee_id']]);$calls=$q->fetchAll();
        out(['employee'=>$e,'stats'=>['total_calls'=>(int)($stats['total_calls']??0),'successful_calls'=>(int)($stats['successful_calls']??0),'unsuccessful_calls'=>(int)($stats['unsuccessful_calls']??0),'total_duration'=>(int)($stats['total_duration']??0)],'calls'=>$calls,'server_time'=>gmdate('c')]);
    }

    if($path==='/api/dashboard' && $method==='GET'){
        manager();
        $rows=$p->query("SELECT e.id,e.name,e.department,d.device_id,d.model,d.battery_level,d.last_seen,d.call_state,d.state_changed_at,COALESCE(s.calls_today,0) calls_today,COALESCE(s.successful_calls_today,0) successful_calls_today,COALESCE(s.unsuccessful_calls_today,0) unsuccessful_calls_today,COALESCE(s.talk_seconds_today,0) talk_seconds_today,COALESCE(s.idle_seconds_today,0) idle_seconds_today FROM employees e LEFT JOIN devices d ON d.employee_id=e.id AND d.active=1 LEFT JOIN daily_employee_stats s ON s.employee_id=e.id AND s.stat_date=CURRENT_DATE WHERE e.active=1 ORDER BY e.name")->fetchAll();
        foreach($rows as &$r){$r['computed_status']=statusFor($r);}unset($r);out(['employees'=>$rows,'server_time'=>gmdate('c')]);
    }

    if($path==='/api/employee/detail' && $method==='GET'){
        manager();$id=(int)($_GET['id']??0);if(!$id)out(['error'=>'Employee ID required'],422);
        $q=$p->prepare("SELECT e.id,e.name,e.department,e.active,d.device_id,d.model,d.battery_level,d.last_seen,d.call_state,d.state_changed_at,COALESCE(s.calls_today,0) calls_today,COALESCE(s.successful_calls_today,0) successful_calls_today,COALESCE(s.unsuccessful_calls_today,0) unsuccessful_calls_today,COALESCE(s.talk_seconds_today,0) talk_seconds_today,COALESCE(s.idle_seconds_today,0) idle_seconds_today FROM employees e LEFT JOIN devices d ON d.employee_id=e.id AND d.active=1 LEFT JOIN daily_employee_stats s ON s.employee_id=e.id AND s.stat_date=CURRENT_DATE WHERE e.id=:id LIMIT 1");$q->execute(['id'=>$id]);$e=$q->fetch();if(!$e)out(['error'=>'Employee not found'],404);$e['computed_status']=statusFor($e);
        $q=$p->prepare("SELECT id,contact_number,result,direction,started_at,ended_at,duration_seconds FROM call_history WHERE employee_id=:id ORDER BY started_at DESC LIMIT 100");$q->execute(['id'=>$id]);$e['recent_calls']=$q->fetchAll();out(['employee'=>$e]);
    }

    if($path==='/api/reports/calls' && $method==='GET'){
        manager();
        $from=$_GET['from']??date('Y-m-d'); $to=$_GET['to']??date('Y-m-d'); $employee=(int)($_GET['employee_id']??0);
        $sql="SELECT c.id,e.name,c.contact_number,c.result,c.direction,c.started_at,c.ended_at,c.duration_seconds FROM call_history c JOIN employees e ON e.id=c.employee_id WHERE c.started_at>=:f AND c.started_at<:t";
        $params=['f'=>$from.' 00:00:00','t'=>date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00'];
        if($employee>0){$sql.=' AND c.employee_id=:e';$params['e']=$employee;}
        $sql.=' ORDER BY c.started_at DESC LIMIT 1000';$q=$p->prepare($sql);$q->execute($params);out(['calls'=>$q->fetchAll(),'from'=>$from,'to'=>$to]);
    }

    if($path==='/api/reports/calls.csv' && $method==='GET'){
        manager();$from=$_GET['from']??date('Y-m-d');$to=$_GET['to']??date('Y-m-d');$toEx=date('Y-m-d',strtotime($to.' +1 day'));
        $q=$p->prepare("SELECT e.name,e.department,c.contact_number,c.result,c.direction,c.started_at,c.ended_at,c.duration_seconds FROM call_history c JOIN employees e ON e.id=c.employee_id WHERE c.started_at>=:f AND c.started_at<:t ORDER BY c.started_at DESC");$q->execute(['f'=>$from.' 00:00:00','t'=>$toEx.' 00:00:00']);
        header_remove('Content-Type');header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename="call-history.csv"');$o=fopen('php://output','w');fputcsv($o,['Employee','Department','Contact Number','Result','Direction','Started','Ended','Duration Seconds']);while($r=$q->fetch())fputcsv($o,$r);fclose($o);exit;
    }

    if($path==='/api/employee/warn' && $method==='POST'){
        manager();$b=body();$id=(int)($b['employee_id']??0);$msg=trim((string)($b['message']??'You are idle. Please resume calling now.'));if(!$id)out(['error'=>'Employee ID required'],422);if(!$msg)$msg='You are idle. Please resume calling now.';
        $q=$p->prepare('SELECT e.id,d.device_id FROM employees e JOIN devices d ON d.employee_id=e.id AND d.active=1 WHERE e.id=:id LIMIT 1');$q->execute(['id'=>$id]);$e=$q->fetch();if(!$e)out(['error'=>'Employee or active device not found'],404);
        $q=$p->prepare("SELECT id FROM warning_commands WHERE device_id=:d AND acknowledged_at IS NULL ORDER BY id LIMIT 1");$q->execute(['d'=>$e['device_id']]);
        if($q->fetchColumn()) out(['ok'=>true,'queued'=>false,'already_pending'=>true]);
        $q=$p->prepare("INSERT INTO warning_commands(employee_id,device_id,command_type,message) VALUES(:e,:d,'IDLE_WARNING',:m)");$q->execute(['e'=>$id,'d'=>$e['device_id'],'m'=>mb_substr($msg,0,255)]);
        // A manual warning starts a fresh idle period. Without this reset, if the
        // employee had already exceeded the automatic-warning threshold, the next
        // 5-second idle-check could immediately create AUTO_IDLE_WARNING after the
        // manual warning is acknowledged.
        $q=$p->prepare("UPDATE devices SET state_changed_at=NOW() WHERE device_id=:d AND active=1 AND call_state='READY'");$q->execute(['d'=>$e['device_id']]);
        audit('WARN_EMPLOYEE',['employee_id'=>$id]);out(['ok'=>true,'queued'=>true]);
    }
    if($path==='/api/employee/commands' && $method==='GET'){
        $device=trim((string)($_GET['device_id']??''));
        if(!$device)out(['error'=>'Device ID required'],422);
        // Deliver warnings strictly one at a time. A second warning must wait until
        // the employee acknowledges the first, preventing duplicate/rapid overlay
        // creation and lost commands when several warnings are queued together.
        $q=$p->prepare("SELECT id,command_type,message,created_at
            FROM warning_commands
            WHERE device_id=:d
              AND acknowledged_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM warning_commands w2
                  WHERE w2.device_id=:d2
                    AND w2.acknowledged_at IS NULL
                    AND w2.id < warning_commands.id
              )
            ORDER BY id
            LIMIT 1");
        $q->execute(['d'=>$device,'d2'=>$device]);
        $c=$q->fetchAll();
        if($c){
            $q=$p->prepare('UPDATE warning_commands SET delivered_at=NOW() WHERE id=:id AND delivered_at IS NULL');
            $q->execute(['id'=>(int)$c[0]['id']]);
        }
        out(['commands'=>$c]);
    }
    if($path==='/api/employee/command-ack' && $method==='POST'){
        $b=body();$id=(int)($b['command_id']??0);$device=trim((string)($b['device_id']??''));if($id<=0||$device==='')out(['ok'=>false,'error'=>'Command ID and device ID required'],422);
        // IMPORTANT: keep ACK autocommit. A failed statement inside a PostgreSQL
        // transaction leaves that transaction aborted (25P02), which previously made
        // the warning impossible to acknowledge and caused the Android client to poll
        // and display the same warning repeatedly.
        try{
            $q=$p->prepare("UPDATE warning_commands SET acknowledged_at=COALESCE(acknowledged_at,NOW()) WHERE id=:i AND device_id=:d RETURNING command_type");
            $q->execute(['i'=>$id,'d'=>$device]);
            $type=$q->fetchColumn();
            if($type===false)out(['ok'=>false,'error'=>'Warning command not found'],404);

            if($type==='AUTO_IDLE_WARNING' || $type==='IDLE_WARNING'){
                // Clear any remaining automatic warning for this same idle period and
                // restart the idle clock. Each statement is autocommit and independent.
                if($type==='AUTO_IDLE_WARNING'){
                    $q=$p->prepare("UPDATE warning_commands SET acknowledged_at=NOW() WHERE device_id=:d AND command_type='AUTO_IDLE_WARNING' AND acknowledged_at IS NULL");
                    $q->execute(['d'=>$device]);
                }
                $q=$p->prepare("UPDATE devices SET state_changed_at=NOW() WHERE device_id=:d AND active=1 AND call_state='READY'");
                $q->execute(['d'=>$device]);
            }
            out(['ok'=>true,'reset_idle_timer'=>true,'command_type'=>$type]);
        }catch(Throwable $e){
            error_log('WARNING ACK ERROR: '.get_class($e).' | SQLSTATE='.($e instanceof PDOException?$e->getCode():'').' | detail='.$e->getMessage().' | command_id='.$id.' | device='.substr($device,0,16));
            throw $e;
        }
    }

    if($path==='/api/diagnostic' && $method==='GET'){
        manager();$device=trim((string)($_GET['device_id']??''));$checks=[];$errors=[];
        try{$p->query('SELECT 1');$checks[]=['name'=>'Database connection','ok'=>true,'detail'=>'PostgreSQL connection is working.'];}catch(Throwable $e){$errors[]=$e->getMessage();$checks[]=['name'=>'Database connection','ok'=>false,'detail'=>'Cannot connect to PostgreSQL.'];}
        foreach(['managers','employees','devices','join_tokens','call_history','daily_employee_stats','warning_commands'] as $t){try{$q=$p->query('SELECT COUNT(*) FROM "'.$t.'"');$checks[]=['name'=>'Table '.$t,'ok'=>true,'detail'=>$q->fetchColumn().' rows'];}catch(Throwable $e){$checks[]=['name'=>'Table '.$t,'ok'=>false,'detail'=>'Missing or inaccessible table.'];}}
        if($device!==''){
            $q=$p->prepare('SELECT d.id,d.device_id,d.active,d.last_seen,d.call_state,e.id employee_id,e.name FROM devices d JOIN employees e ON e.id=d.employee_id WHERE d.device_id=:d LIMIT 1');$q->execute(['d'=>$device]);$r=$q->fetch();
            if(!$r){$checks[]=['name'=>'Device registration','ok'=>false,'detail'=>'No row exists in devices for this Android device ID.'];$errors[]='DEVICE_NOT_REGISTERED';}
            else{$checks[]=['name'=>'Device registration','ok'=>true,'detail'=>'Registered to '.$r['name'].' (employee #'.$r['employee_id'].').'];$checks[]=['name'=>'Device active','ok'=>(bool)$r['active'],'detail'=>$r['active']?'Active':'Inactive'];$age=$r['last_seen']?time()-strtotime($r['last_seen']):null;$checks[]=['name'=>'Heartbeat','ok'=>$age!==null && $age<=45,'detail'=>$r['last_seen']?'Last seen '.$r['last_seen'].' ('.max(0,(int)$age).'s ago)':'No heartbeat recorded'];$checks[]=['name'=>'Call state','ok'=>true,'detail'=>$r['call_state']];}
        }
        out(['ok'=>count(array_filter($checks,fn($c)=>!$c['ok']))===0,'checks'=>$checks,'errors'=>$errors,'server_time'=>gmdate('c')]);
    }

    out(['error'=>'Not found','path'=>$path,'method'=>$method,'hint'=>'Use /api/health for API health checks.'],404);
} catch(Throwable $e){
    error_log('API ERROR: '.$e->getMessage());
    $isHealth = ($path==='/api' || $path==='/api/' || $path==='/api/health' || $path==='/api/index.php');
    if($isHealth) out(['ok'=>false,'error'=>'Database connection failed','detail'=>'The API is reachable, but PHP could not connect to PostgreSQL. Check the DB_* environment variables and database status.','php'=>PHP_VERSION],500);
    if(!empty($_SESSION['manager_id'])) out(['error'=>'Server error','code'=>'API_SERVER_ERROR','detail'=>'Database/API query failed: '.$e->getMessage()],500); out(['error'=>'Server error','code'=>'API_SERVER_ERROR','detail'=>'The API was reached but could not complete the request. Open /api/health to diagnose the server.'],500);
}
