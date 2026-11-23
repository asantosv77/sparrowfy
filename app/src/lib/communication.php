<?php
declare(strict_types=1);
/** Private account communication. This module never logs message contents. */
function scom_schema(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS sf_com_notices(id TEXT PRIMARY KEY,author TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,cancelled_at INTEGER,request_key TEXT NOT NULL,UNIQUE(author,request_key));
 CREATE TABLE IF NOT EXISTS sf_com_recipients(notice_id TEXT NOT NULL,user_id TEXT NOT NULL,confirmed_at INTEGER,PRIMARY KEY(notice_id,user_id));
 CREATE INDEX IF NOT EXISTS sf_com_pending ON sf_com_recipients(user_id,confirmed_at,notice_id);
 CREATE TABLE IF NOT EXISTS sf_com_threads(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,subject TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,request_key TEXT NOT NULL,UNIQUE(user_id,request_key));
 CREATE INDEX IF NOT EXISTS sf_com_thread_owner ON sf_com_threads(user_id,updated_at);
 CREATE TABLE IF NOT EXISTS sf_com_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,thread_id TEXT NOT NULL,author TEXT NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,request_key TEXT NOT NULL,UNIQUE(author,request_key));
 CREATE INDEX IF NOT EXISTS sf_com_messages_thread ON sf_com_messages(thread_id,id);
 CREATE TABLE IF NOT EXISTS sf_com_reads(thread_id TEXT NOT NULL,user_id TEXT NOT NULL,last_id INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(thread_id,user_id));
 CREATE TABLE IF NOT EXISTS sf_com_preferences(user_id TEXT PRIMARY KEY,notify INTEGER NOT NULL DEFAULT 0);");
}
function scom_admin(array $account): bool {return ($account['role']??'')==='admin';}
function scom_text(mixed $value,int $max): string {
 if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('Texto inválido.');
 $value=trim($value);if($value===''||mb_strlen($value)>$max)throw new InvalidArgumentException("Completa el texto; máximo $max caracteres.");
 return $value;
}
function scom_key(array $input): string {
 $key=$input['request_key']??'';if(!is_string($key)||!preg_match('/^[a-f0-9]{32,64}$/D',$key))throw new InvalidArgumentException('Identificador de envío inválido. Recarga el formulario.');return $key;
}
function scom_query(PDO $db,string $sql,array $args=[]): array {$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
function scom_thread(PDO $db,array $account,string $id): array {
 $rows=scom_query($db,'SELECT * FROM sf_com_threads WHERE id=?',[$id]);$t=$rows[0]??null;
 if(!$t||(!scom_admin($account)&&(string)$t['user_id']!==(string)$account['id']))throw new DomainException('Conversación no disponible.');return $t;
}
function scom_rate(PDO $db,string $user,string $table,string $column,int $max,int $window): void {
 // Table and column names are supplied only by the fixed calls below.
 $q=$db->prepare("SELECT COUNT(*) FROM $table WHERE $column=? AND created_at>?");$q->execute([$user,time()-$window]);
 if((int)$q->fetchColumn()>=$max)throw new InvalidArgumentException('Has enviado varios mensajes. Espera un momento antes de continuar.');
}
function scom_mutate(PDO $db,array $account,array $in): array {
 $user=(string)$account['id'];$admin=scom_admin($account);$action=(string)($in['action']??'');
 $db->exec('BEGIN IMMEDIATE');
 try {
  $result=['success'=>true];
  if($action==='publish'){
   if(!$admin)throw new DomainException('Acceso reservado al administrador.');
   $key=scom_key($in);$old=scom_query($db,'SELECT id FROM sf_com_notices WHERE author=? AND request_key=?',[$user,$key]);
   if($old){$db->exec('COMMIT');return $result+['id'=>$old[0]['id']];}
   $title=scom_text($in['title']??null,140);$body=scom_text($in['body']??null,6000);$audience=(string)($in['audience']??'');
   $sql='SELECT id FROM users WHERE is_active=1';$args=[];
   if($audience==='managers')$sql.=" AND (role='admin' OR can_manage_albums=1)";
   elseif($audience==='selected'){
    $ids=$in['recipients']??[];if(!is_array($ids)||!$ids||count($ids)>500)throw new InvalidArgumentException('Selecciona los destinatarios.');
    foreach($ids as $id)if(!is_string($id)||strlen($id)>128)throw new InvalidArgumentException('Destinatario inválido.');
    $sql.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=$ids;
   }elseif($audience!=='all')throw new InvalidArgumentException('Selecciona los destinatarios.');
   $users=scom_query($db,$sql,$args);if(!$users)throw new InvalidArgumentException('No hay destinatarios activos.');
   scom_rate($db,$user,'sf_com_notices','author',10,3600);$id=bin2hex(random_bytes(16));$now=time();
   $db->prepare('INSERT INTO sf_com_notices VALUES(?,?,?,?,?,NULL,?)')->execute([$id,$user,$title,$body,$now,$key]);
   $q=$db->prepare('INSERT INTO sf_com_recipients(notice_id,user_id) VALUES(?,?)');foreach($users as $u)$q->execute([$id,(string)$u['id']]);
   $result+=['id'=>$id,'count'=>count($users)];
  }elseif($action==='ack'){
   $id=(string)($in['id']??'');$q=$db->prepare('UPDATE sf_com_recipients SET confirmed_at=COALESCE(confirmed_at,?) WHERE notice_id=? AND user_id=? AND EXISTS(SELECT 1 FROM sf_com_notices n WHERE n.id=notice_id AND n.cancelled_at IS NULL)');$q->execute([time(),$id,$user]);
   // Repeated confirmation is safe; a different account cannot acknowledge this recipient.
   if(!$q->rowCount())throw new DomainException('El aviso ya no está pendiente o no está dirigido a tu cuenta.');
  }elseif($action==='cancel'){
   if(!$admin)throw new DomainException('Acceso reservado al administrador.');
   $db->prepare('UPDATE sf_com_notices SET cancelled_at=COALESCE(cancelled_at,?) WHERE id=?')->execute([time(),(string)($in['id']??'')]);
  }elseif($action==='create'){
   $key=scom_key($in);$old=scom_query($db,'SELECT id FROM sf_com_threads WHERE user_id=? AND request_key=?',[$user,$key]);
   if($old){$db->exec('COMMIT');return $result+['id'=>$old[0]['id']];}
   $subject=scom_text($in['subject']??null,140);$body=scom_text($in['body']??null,6000);
   scom_rate($db,$user,'sf_com_threads','user_id',5,3600);scom_rate($db,$user,'sf_com_messages','author',20,3600);
   $id=bin2hex(random_bytes(16));$now=time();$db->prepare('INSERT INTO sf_com_threads VALUES(?,?,?,\'new\',?,?,?)')->execute([$id,$user,$subject,$now,$now,$key]);
   $db->prepare('INSERT INTO sf_com_messages(thread_id,author,body,created_at,request_key) VALUES(?,?,?,?,?)')->execute([$id,$user,$body,$now,$key]);$result+=['id'=>$id];
  }elseif($action==='reply'){
   $id=(string)($in['id']??'');$t=scom_thread($db,$account,$id);$key=scom_key($in);
   $old=scom_query($db,'SELECT thread_id FROM sf_com_messages WHERE author=? AND request_key=?',[$user,$key]);
   if($old){if($old[0]['thread_id']!==$id)throw new InvalidArgumentException('Identificador ya utilizado.');$db->exec('COMMIT');return $result;}
   $body=scom_text($in['body']??null,6000);if($t['status']==='closed')throw new InvalidArgumentException('Esta conversación está cerrada.');
   scom_rate($db,$user,'sf_com_messages','author',20,3600);$now=time();
   $db->prepare('INSERT INTO sf_com_messages(thread_id,author,body,created_at,request_key) VALUES(?,?,?,?,?)')->execute([$id,$user,$body,$now,$key]);
   $db->prepare('UPDATE sf_com_threads SET status=?,updated_at=? WHERE id=?')->execute([$admin?'answered':'new',$now,$id]);
  }elseif($action==='status'){
   if(!$admin)throw new DomainException('Acceso reservado al administrador.');$id=(string)($in['id']??'');scom_thread($db,$account,$id);
   $status=(string)($in['status']??'');if(!in_array($status,['new','review','answered','closed'],true))throw new InvalidArgumentException('Estado inválido.');
   $db->prepare('UPDATE sf_com_threads SET status=?,updated_at=? WHERE id=?')->execute([$status,time(),$id]);
  }elseif($action==='read'){
   $id=(string)($in['id']??'');scom_thread($db,$account,$id);
   $last=filter_var($in['last_id']??'',FILTER_VALIDATE_INT);if($last===false||$last<0)throw new InvalidArgumentException('Lectura inválida.');
   $q=$db->prepare('SELECT COALESCE(MAX(id),0) FROM sf_com_messages WHERE thread_id=?');$q->execute([$id]);$last=min($last,(int)$q->fetchColumn());
   $db->prepare('INSERT INTO sf_com_reads VALUES(?,?,?) ON CONFLICT(thread_id,user_id) DO UPDATE SET last_id=MAX(last_id,excluded.last_id)')->execute([$id,$user,$last]);
  }elseif($action==='preference'){
   if(!$admin)throw new DomainException('Acceso reservado al administrador.');
   if(!in_array($in['notify']??null,['0','1'],true))throw new InvalidArgumentException('Preferencia inválida.');
   $db->prepare('INSERT INTO sf_com_preferences VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET notify=excluded.notify')->execute([$user,(int)$in['notify']]);
  }else throw new InvalidArgumentException('Acción no permitida.');
  $db->exec('COMMIT');return $result;
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function scom_pending(PDO $db,string $user): array {
 return scom_query($db,'SELECT n.id,n.title,n.body,n.created_at FROM sf_com_recipients r JOIN sf_com_notices n ON n.id=r.notice_id WHERE r.user_id=? AND r.confirmed_at IS NULL AND n.cancelled_at IS NULL ORDER BY n.created_at,n.id LIMIT 1',[$user]);
}
function scom_unread(PDO $db,array $account): int {
 $sql='SELECT COUNT(*) FROM sf_com_threads t WHERE EXISTS(SELECT 1 FROM sf_com_messages m WHERE m.thread_id=t.id AND m.author<>? AND m.id>COALESCE((SELECT last_id FROM sf_com_reads r WHERE r.thread_id=t.id AND r.user_id=?),0))';$args=[(string)$account['id'],(string)$account['id']];
 if(!scom_admin($account)){$sql.=' AND t.user_id=?';$args[]=(string)$account['id'];}
 $q=$db->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
}
