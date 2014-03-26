<?php

/*
  Author: Mark Maunder <mmaunder@gmail.com>
  Author website: http://markmaunder.com/
  License: GPL 3.0
*/


define( 'ERRORLOGFILE' , '/var/log/wp_errors' );

class deploymint{

  private static $wpTables = array(
    'commentmeta' ,
    'comments' ,
    'links' ,
    'options' ,
    'postmeta' ,
    'posts' ,
    'term_relationships' ,
    'term_taxonomy' ,
    'terms'
  );

  public static function installPlugin(){
    $sql_createTables = array(
      "CREATE TABLE IF NOT EXISTS `dep_options` (
         `name` VARCHAR(100) NOT NULL PRIMARY KEY ,
         `val` VARCHAR(255) DEFAULT ''
       ) DEFAULT CHARSET=utf8" ,
      "CREATE TABLE IF NOT EXISTS `dep_projects` (
         `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
         `ctime` INT(11) UNSIGNED NOT NULL ,
         `name` VARCHAR(100) NOT NULL ,
         `dir` VARCHAR(120) NOT NULL ,
         `deleted` TINYINT UNSIGNED DEFAULT 0
       ) DEFAULT CHARSET=utf8" ,
      "CREATE TABLE IF NOT EXISTS `dep_members` (
         `blog_id` INT(11) UNSIGNED NOT NULL ,
         `project_id` INT(11) UNSIGNED NOT NULL ,
         `deleted` TINYINT UNSIGNED DEFAULT 0 ,
         KEY k1( `blog_id` , `project_id` )
       ) DEFAULT CHARSET=utf8"
    );
    if( !( $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true ) )
        || !mysql_select_db( DB_NAME , $dbh ) )
      die( 'Database error creating table for DeployMint: ' . mysql_error( $dbh ) );
    foreach( $sql_createTables as $sql_statement ){
      mysql_query( $sql_statement , $dbh);
      if( mysql_error( $dbh ) )
        die( 'Database error creating table for DeployMint: ' . mysql_error( $dbh ) );
    }
    $options = self::getOptions();
    foreach (array('git', 'mysql', 'mysqldump', 'rsync') as $n) {
      $options[$n] = $options[$n] ? $options[$n] : trim(self::mexec("which $n"));
    }
    self::updateOptions($options);
  }

  private static function getDefaultOptions(){
    return array(
      'git'               => '' ,
      'mysql'             => '' ,
      'mysqldump'         => '' ,
      'rsync'             => '' ,
      'numBackups'        => 5 ,
      'datadir'           => '' ,
      'preserveBlogName'  => 1 ,
      'backupDisabled'    => 0 ,
      'temporaryDatabase' => '' ,
      'backupDatabase'    => ''
    );
  }

  private static function getOptions( $createTemporaryDatabase = false , $createBackupDatabase = false ){
    global $wpdb;
    if( !( $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true ) )
        || !mysql_select_db( DB_NAME , $dbh ) )
      die( 'Database error in DeployMint: ' . mysql_error( $dbh ) );
    $res = $wpdb->get_results( $wpdb->prepare( 'SELECT `name` , `val` FROM `dep_options`' ) , ARRAY_A );
    $options = self::getDefaultOptions();
    for( $i = 0 , $c = count( $res ) ; $i < $c ; $i++ ){
      $options[$res[$i]['name']] = $res[$i]['val'];
    }
    $options['backupDisabled'] = ( $options['backupDisabled']=='1' );
    $options['temporaryDatabaseCreated'] = false;
    if( $options['temporaryDatabase']=='' && $createTemporaryDatabase ){
      for( $i = 1 ; $i < 10 ; $i++ ){
        $options['temporaryDatabase'] = 'dep_tmpdb' . preg_replace( '/\./' , '' , microtime( true ) );
        $res = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES FROM ' . $options['temporaryDatabase'] ) , ARRAY_A );
        if( count( $res ) < 1 )
          break;
        if( $i > 4 )
          self::ajaxError( 'We could not create a temporary database name after 5 tries. You may not have the create DB privilege.' );
      }
      $wpdb->query( $wpdb->prepare( 'CREATE DATABASE ' . $options['temporaryDatabase'] ) );
      $options['temporaryDatabaseCreated'] = true;
    }
    $options['backupDatabaseCreated'] = false;
    if( $createBackupDatabase && !$options['backupDisabled'] && $options['numBackups']!=1 ){
      $dbPrefix = ( $options['backupDatabase']=='' ? 'depbak' : $options['backupDatabase'] );
      $options['backupDatabase'] = $dbPrefix . '__' . preg_replace( '/\./' , '' , microtime( true ) );
      if( !mysql_query( 'CREATE DATABASE ' . $options['backupDatabase'] , $dbh ) )
        self::ajaxError( 'Could not create backup database. ' . mysql_error( $dbh ) );
      $options['backupDatabaseCreated'] = true;
    }
    return $options;
  }

  private static function emptyDatabase( $database , $connection ){
    if( $result = mysql_query( 'SHOW TABLES IN ' . $database , $connection ) ){
      while( $row = mysql_fetch_array( $result , MYSQL_NUM ) ){
        mysql_query( 'DROP TABLE IF EXISTS ' . $row[0] , $connection );
      }
    }
  }

  private static function updateOptions( $o ){
    foreach( $o as $n => $v ){
      self::setOption( $n , $v );
    }
  }

  private static function setOption( $name , $val ){
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'INSERT INTO `dep_options` ( `name` , `val` ) VALUES ( %s , %s ) ON DUPLICATE KEY UPDATE `val` = %s' , $name , $val , $val ) );
  }

  private static function allOptionsSet(){
    global $wpdb;
    $options = self::getOptions();
    foreach( array( 'git' , 'mysql' , 'mysqldump' , 'rsync' , 'datadir' ) as $v ){
      if( !$options[$v] )
        return false;
    }
    return preg_match( '/^\d+$/' , $options['numBackups'] );
  }

  public static function setup(){
    global $wpdb;
    if( is_network_admin() && is_multisite() ){
      add_action( 'network_admin_menu' , 'deploymint::adminMenuHandler' );
    }elseif( is_admin() && !is_multisite() ){
      add_action( 'admin_menu' , 'deploymint::adminMenuHandler' );
    }
    add_action( 'init', 'deploymint::initHandler');
    add_action( 'wp_enqueue_scripts', 'deploymint::enqueue_scripts');
    add_action( 'wp_enqueue_styles', 'deploymint::enqueue_styles');
    
    add_action( 'wp_ajax_deploymint_deploy' , 'deploymint::ajax_deploy_callback' );
    add_action( 'wp_ajax_deploymint_createProject' , 'deploymint::ajax_createProject_callback' );
    add_action( 'wp_ajax_deploymint_reloadProjects' , 'deploymint::ajax_reloadProjects_callback' );
    add_action( 'wp_ajax_deploymint_updateCreateSnapshot' , 'deploymint::ajax_updateCreateSnapshot_callback' );
    add_action( 'wp_ajax_deploymint_updateDeploySnapshot' , 'deploymint::ajax_updateDeploySnapshot_callback' );
    add_action( 'wp_ajax_deploymint_updateSnapDesc' , 'deploymint::ajax_updateSnapDesc_callback' );
    add_action( 'wp_ajax_deploymint_createSnapshot' , 'deploymint::ajax_createSnapshot_callback' );
    add_action( 'wp_ajax_deploymint_deploySnapshot' , 'deploymint::ajax_deploySnapshot_callback' );
    add_action( 'wp_ajax_deploymint_undoDeploy' , 'deploymint::ajax_undoDeploy_callback' );
    add_action( 'wp_ajax_deploymint_addBlogToProject' , 'deploymint::ajax_addBlogToProject_callback' );
    add_action( 'wp_ajax_deploymint_removeBlogFromProject' , 'deploymint::ajax_removeBlogFromProject_callback' );
    add_action( 'wp_ajax_deploymint_deleteProject' , 'deploymint::ajax_deleteProject_callback' );
    add_action( 'wp_ajax_deploymint_deleteBackups' , 'deploymint::ajax_deleteBackups_callback' );
    add_action( 'wp_ajax_deploymint_updateOptions' , 'deploymint::ajax_updateOptions_callback' );
    if( !self::allOptionsSet() ){
      if( is_multisite() ){
        add_action( 'network_admin_notices' , 'deploymint::msgDataDir' );
      }else{
        add_action( 'admin_notices' , 'deploymint::msgDataDir' );
      }
    }
  }

  public static function enqueue_scripts(){
    /*
    wp_deregister_script( 'jquery' );
    wp_enqueue_script( 'jquery' , plugin_dir_url( __FILE__ ) . 'js/jquery-1.6.2.js' , array( ) );
    */
  }

  public static function enqueue_styles(){
    wp_register_style( 'DeployMintCSS' , plugin_dir_url( __FILE__ ) . 'css/admin.css' );
    wp_enqueue_style( 'DeployMintCSS' );
  }

  public static function __callStatic( $name , $args ){
    $matches = array();
    if( preg_match( '/^projectMenu(\d+)$/' , $name , $matches ) ){
      self::projectMenu( $matches[1] );
    }else{
      die( "Method $name doesn't exist!" );
    }
  }

  private static function checkPerms(){
    if( !is_user_logged_in() )
      die( '<h2>You are not logged in.</h2>' );
    if( ( is_multisite() && !current_user_can( 'manage_network' ) ) 
     || ( !is_multisite() && !current_user_can( 'manage_options' ) ) )
      die( '<h2>You do not have permission to access this page.</h2><p>You need the "manage_network" Super Admin capability to use DeployMint.</p>' );
  }

  public static function projectMenu( $projectid ){
    self::checkPerms();
    global $wpdb;
    if( !self::allOptionsSet() ){
      echo '<div class="wrap"><h2 class="depmintHead">Please visit the options page and configure all options</h2></div>';
      return;
    }
    $res = $wpdb->get_results($wpdb->prepare( 'SELECT * FROM `dep_projects` WHERE `id` = %d AND `deleted` = 0' , $projectid ) , ARRAY_A );
    $proj = $res[0];
    include( 'views/projectPage.php' );
  }

  public static function ajax_createProject_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions();
    extract( $opt , EXTR_OVERWRITE );
    $exists = $wpdb->get_results( $wpdb->prepare( 'SELECT `name` FROM `dep_projects` WHETE `name` = %s AND `deleted` = 0' , $_POST['name'] ) , ARRAY_A );
    if( count( $exists ) )
      die( json_encode( array( 'err' => 'A project with that name already exists.' ) ) );
    $dir = $_POST['name'];
    $dir = preg_replace( '/[^a-zA-Z0-9]+/' , '_' , $dir );
    $fulldir = $dir . '-1';
    $counter = 2;
    while( is_dir( $datadir . $fulldir ) ){
      $fulldir = preg_replace( '/\-\d+$/' , '' , $fulldir );
      $fulldir .= '-' . $counter;
      $counter++;
      if( $counter > 1000 )
        die( json_encode( array( 'err' => "Too many directories already exist starting with \"$dir\"" ) ) );
    }
    $finaldir = $datadir . $fulldir;
    if( !@mkdir( $finaldir , 0755 ) )
      die( json_encode( array( 'err' => "Could not create directory $finaldir" ) ) );
    $git1 = self::mexec( "$git init ; $git add . " , $finaldir );
    $wpdb->query( $wpdb->prepare( 'INSERT INTO `dep_projects` ( `ctime` , `name` , `dir` ) VALUES ( UNIX_TIMESTAMP() , %s , %s )' , $_POST['name'] , $fulldir ) );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_updateOptions_callback(){
    self::checkPerms();
    $defaultOptions = self::getDefaultOptions();
    $P = array_map( 'trim' , $_POST );
    $git               = $P['git'];
    $mysql             = $P['mysql'];
    $mysqldump         = $P['mysqldump'];
    $rsync             = $P['rsync'];
    $numBackups        = $P['numBackups'];
    $temporaryDatabase = $P['temporaryDatabase'];
    $backupDisabled    = ( $P['backupDisabled']!='' ? 1 : 0 );
    $backupDatabase    = $P['backupDatabase'];
    $datadir           = $P['datadir'];
    if( substr( $datadir , -1 )!='/' )
      $datadir .= '/';
    $errs = array();
    if( !( $git && $mysql && $mysqldump && $rsync && $datadir ) )
      $errs[] = 'You must specify a value for all options.';
    if( !preg_match('/^\d+$/' , $numBackups ) ){
      if( $backupDisabled ){
        $numBackups = $defaultOptions['numBackups'];
      } else {
        $errs[] = 'The number of backups you specify must be a number or 0 to keep all backups.';
      }
    }
    $preserveBlogName = trim($_POST['preserveBlogName']);
    if( $preserveBlogName!=0 && $preserveBlogName!=1 )
      $errs[] = "Invalid value for preserveBlogName. Expected 1 or 0. Received $preserveBlogName";
    if( count( $errs ) )
      die( json_encode( array( 'errs' => $errs ) ) );
    if( !file_exists( $mysql ) )
      $errs[] = "The file '$mysql' specified for mysql doesn't exist.";
    if( !file_exists( $mysqldump ) )
      $errs[] = "The file '$mysqldump' specified for mysqldump doesn't exist.";
    if( !file_exists( $rsync ) )
      $errs[] = "The file '$rsync' specified for rsync doesn't exist.";
    if( !file_exists( $git ) )
      $errs[] = "The file '$git' specified for git doesn't exist.";
    if( !is_dir( $datadir ) ){
      $errs[] = "The directory '$datadir' specified as the data directory doesn't exist.";
    }else{
      $fh = fopen( $datadir . '/test.tmp' , 'w' );
      if( !fwrite( $fh , 't' ) )
        $errs[] = "The directory $datadir is not writeable.";
      fclose( $fh );
      unlink( $datadir . '/test.tmp' );
    }
    if( count( $errs ) )
      die( json_encode( array( 'errs' => $errs ) ) );
    $options = array(
      'git'               => $git ,
      'mysql'             => $mysql ,
      'mysqldump'         => $mysqldump ,
      'rsync'             => $rsync ,
      'datadir'           => $datadir ,
      'numBackups'        => $numBackups ,
      'temporaryDatabase' => $temporaryDatabase ,
      'backupDisabled'    => $backupDisabled ,
      'backupDatabase'    => $backupDatabase ,
      'preserveBlogName'  => $preserveBlogName
    );
    self::updateOptions( $options );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_deleteBackups_callback(){
    self::checkPerms();
    if( !( $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true ) ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $toDel = $_POST['toDel'];
    for( $i = 0 , $c = count( $toDel ) ; $i < $c ; $i++ ){
      mysql_query( 'DROP DATABASE ' . $toDel[$i] , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'Could not drop database ' . $toDel[$i] . '. Error: ' . mysql_error( $dbh ) );
    }
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_deleteProject_callback(){
    self::checkPerms();
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'UPDATE `dep_members` SET `deleted` = 1 WHERE `project_id` = %d' , $_POST['blogID'] , $_POST['projectID'] ) );
    $wpdb->query( $wpdb->prepare( 'UPDATE `dep_projects` SET `deleted` = 1 WHERE `id` = %d' , $_POST['projectID'] ) );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_removeBlogFromProject_callback(){
    self::checkPerms();
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'UPDATE `dep_members` SET `deleted` = 1 WHERE `blog_id` = %d AND `project_id` = %d' , $_POST['blogID'] , $_POST['projectID'] ) );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_addBlogToProject_callback(){
    self::checkPerms();
    global $wpdb;
    $det = get_blog_details( $_POST['blogID'] );
    if( !$det )
      die( json_encode( array( 'err' => 'Please select a valid blog to add.' ) ) );
    $wpdb->query( $wpdb->prepare( 'INSERT INTO `dep_members` ( `blog_id` , `project_id` ) VALUES ( %d , %d )' , $_POST['blogID'] , $_POST['projectID'] ) );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_createSnapshot_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions();
    extract( $opt , EXTR_OVERWRITE );
    $pid = $_POST['projectid'];
    $blogid = $_POST['blogid'];
    $name = $_POST['name'];
    $desc = $_POST['desc'];
    if( !preg_match( '/\w+/' , $name ) )
      self::ajaxError( 'Please enter a name for this snapshot' );
    if( strlen( $name ) > 20 )
      self::ajaxError( 'Your snapshot name must be 20 characters or less.' );
    if( preg_match( '/[^a-zA-Z0-9\_\-\.]/' , $name ) )
      self::ajaxError( 'Your snapshot name can only contain characters a-z A-Z 0-9 and dashes, underscores and dots.' );
    if( !$desc )
      self::ajaxError( 'Please enter a description for this snapshot.' );
    $prec = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `dep_projects` WHERE `id` = %d AND `deleted` = 0' , $pid ) , ARRAY_A );
    if( !count( $prec ) )
      self::ajaxError( 'That project does not exist.' );
    $proj = $prec[0];
    $dir = $datadir . $proj['dir'] . '/';
    $mexists = $wpdb->get_results( $wpdb->prepare( 'SELECT `blog_id` FROM `dep_members` WHERE `blog_id` = %d AND `project_id` = %d AND `deleted` = 0' , $blogid , $pid ) , ARRAY_A );
    if( !count( $mexists ) )
      self::ajaxError( 'That blog does not exist or is not a member of this project.' );
    if( !is_dir( $dir ) )
      self::ajaxError( "The directory $dir for this project doesn't exist for some reason. Did you delete it?" );
    $branchOut = self::mexec( "$git branch 2>&1" , $dir );
    if( preg_match( '/fatal/' , $branchOut ) )
      self::ajaxError( "The directory $dir is not a valid git repository. The output we received is: $branchOut" );
    $branches = preg_split( '/[\r\n\s\t\*]+/' , $branchOut );
    $bdup = array();
    for( $i = 0 , $c = count( $branches ) ; $i < $c ; $i++ ){
      $bdup[$branches[$i]] = 1;
    }
    if( array_key_exists( $name , $bdup ) )
      self::ajaxError( "A snapshot with the name $name already exists. Please choose another." );
    $cout1 = self::mexec( "$git checkout master 2>&1" , $dir );
   // Before we do our initial commit we will get an error trying to checkout master because it doesn't exist.
    if( !preg_match( "/(?:Switched to branch|Already on|error: pathspec 'master' did not match)/" , $cout1 ) )
      self::ajaxError( "We could not switch the git repository in $dir to 'master'. The output was: $cout1" );
    $prefix = '';
    if( $blogid==1 ){
      $prefix = $wpdb->base_prefix;
    } else {
      $prefix = $wpdb->base_prefix . $blogid . '_';
    }
    $prefixFile = $dir . 'deployData.txt';
    $fh2 = fopen( $prefixFile , 'w' );
    if( !fwrite( $fh2 , $prefix . ':' . microtime( true ) ) )
      self::ajaxError( "We could not write to deployData.txt in the directory $dir" );
    fclose( $fh2 );
    $prefixOut = self::mexec( "$git add deployData.txt 2>&1" , $dir );

   // Add the Media locations
    $files = self::mexec( "$rsync -r -d " . WP_CONTENT_DIR . "/blogs.dir/$blogid/* {$dir}blogs.dir/" );
    $filesOut = self::mexec( "$git add blogs.dir/ 2>&1" , $dir );

    $siteURLRes = $wpdb->get_results( $wpdb->prepare( 'SELECT `option_name` , `option_value` FROM `' . $prefix . 'options` WHERE `option_name` = "siteurl"' ) , ARRAY_A );
    $siteURL = $siteURLRes[0]['option_value'];
    $desc = "Snapshot of: {$siteURL}\n{$desc}";

    $dumpErrs = array();
    foreach( self::$wpTables as $t ){
      $tableFile = $t . '.sql';
      $tableName = $prefix . $t;
      $path = $dir . $tableFile;
      $dbuser = DB_USER;
      $dbpass = DB_PASSWORD;
      $dbhost = DB_HOST;
      $dbname = DB_NAME;
      $o1 = self::mexec( "{$mysqldump} --skip-comments --extended-insert --complete-insert --skip-comments -u {$dbuser} -p{$dbpass} -h {$dbhost} {$dbname} {$tableName} > {$path} 2>&1" , $dir );
      if( preg_match( '/\w+/' , $o1 ) ){
        array_push( $dumpErrs , $o1 );
      }else{
        $grepOut = self::mexec( "grep CREATE $path 2>&1" );
        if( !preg_match( '/CREATE/' , $grepOut ) ){
          array_push( $dumpErrs , "We could not create a valid table dump file for $tableName" );
        }else{
          $gitAddOut = self::mexec( "$git add $tableFile 2>&1" , $dir );
          if( preg_match( '/\w+/' , $gitAddOut ) )
            self::ajaxError("We encountered an error running '$git add $tableFile' the error was: $gitAddOut");
        }
      }
    }
    if( count( $dumpErrs ) ){
      $resetOut = self::mexec( "$git reset --hard HEAD 2>&1" , $dir );
      if (!preg_match('/HEAD is now at/', $resetOut))
        self::ajaxError( "Errors occured during mysqldump and we could not revert the git repository in {$dir} back to it's original state using '{$git} reset --hard HEAD'. The output we got was: {$resetOut}" );
      self::ajaxError( 'Errors occured during mysqldump: ' . implode( ', ' , $dumpErrs ) );
    }
    $tmpfile = $datadir . microtime( true ) . '.tmp';
    $fh = fopen( $tmpfile , 'w' );
    fwrite( $fh , $desc );
    fclose( $fh );
    global $current_user;
    get_currentuserinfo();
    $commitUser = $current_user->user_firstname . ' ' . $current_user->user_lastname . ' <' . $current_user->user_email . '>';
    $commitOut2 = self::mexec( "$git commit --author=\"$commitUser\" -a -F \"$tmpfile\" 2>&1" , $dir );
    unlink( $tmpfile );
    if( !preg_match( '/files changed/' , $commitOut2 ) )
      self::ajaxError( "git commit failed. The output we got was: $commitOut2" );
    $brOut2 = self::mexec( "$git branch $name 2>&1 " , $dir );
    if( preg_match( '/\w+/' , $brOut2 ) )
      self::ajaxError( "We encountered an error running '$git branch $name' the output was: $brOut2" );
    die( json_encode( array( 'ok' => 1 ) ) );
  }

  public static function ajax_undoDeploy_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions( true );
    extract( $opt , EXTR_OVERWRITE );
    $sourceDBName = $_POST['dbname'];
    if( !( $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true ) )
        || !mysql_select_db( $sourceDBName , $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $res1 = mysql_query( 'SHOW TABLES' , $dbh );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $allTables = array();
    while( $row1 = mysql_fetch_array( $res1 , MYSQL_NUM ) ){
      if( !preg_match( '/^dep_/' , $row1[0] ) )
        array_push( $allTables , $row1[0] );
    }
    $renames = array();
    foreach( $allTables as $t ){
      array_push( $renames , "{$dbname}.{$t} TO {$temporaryDatabase}.{$t} , {$sourceDBName}.{$t} TO {$dbname}.{$t}" );
    }
    $stime = microtime( true );
    mysql_query( 'RENAME TABLE ' . implode( ', ' , $renames ) , $dbh );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $lockTime = sprintf( '%.4f' , microtime( true ) - $stime );
    if( $temporaryDatabaseCreated ){
      mysql_query( "DROP DATABASE {$temporaryDatabase}" , $dbh );
    }else{
      self::emptyDatabase( $temporaryDatabase , $dbh );
    }
    foreach( $allTables as $t ){
      mysql_query( "CREATE TABLE {$sourceDBName}.{$t} LIKE {$dbname}.{$t}" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured trying to recreate the backup database, but the deployment completed. Error: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO {$sourceDBName}.{$t} SELECT * FROM {$dbname}.{$t}" , $dbh );
      if (mysql_error($dbh))
        self::ajaxError( 'A database error occured trying to recreate the backup database, but the deployment completed. Error: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    }
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured (but the revert was completed!): ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    die( json_encode( array( 'ok' => 1 , 'lockTime' => $lockTime ) ) );
  }

  public static function ajax_deploySnapshot_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions( true , true );
    extract( $opt , EXTR_OVERWRITE );
    $pid = $_POST['projectid'];
    $blogid = $_POST['blogid'];
    $name = $_POST['name'];
    $leaveComments = true; //$_POST['leaveComments'];

    if( !preg_match( '/\w+/' , $name ) )
      self::ajaxError( 'Please select a snapshot to deploy.' );
    $prec = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `dep_projects` WHERE `id` = %d AND `deleted` = 0' , $pid ) , ARRAY_A );
    if( !count( $prec ) )
      self::ajaxError( 'That project does not exist.');
    $proj = $prec[0];
    $dir = $datadir . $proj['dir'] . '/';
    $mexists = $wpdb->get_results( $wpdb->prepare( 'SELECT `blog_id` FROM `dep_members` WHERE `blog_id` = %d AND `project_id` = %d AND `deleted` = 0' , $blogid , $pid ) , ARRAY_A );
    if( !count( $mexists ) )
      self::ajaxError( 'That blog does not exist or is not a member of this project. Please select a valid blog to deploy to.' );
    if( !is_dir( $dir ) )
      self::ajaxError( "The directory {$dir} for this project does not exist for some reason. Did you delete it?" );
    $co1 = self::mexec( "$git checkout $name 2>&1" , $dir );
    if( !preg_match( '/(?:Switched|Already)/' , $co1 ) )
      self::ajaxError( "We could not find snapshot $name in the git repository. The error was: $co1" );
    $destTablePrefix = '';
    if( $blogid==1 ){
      $destTablePrefix = $wpdb->base_prefix;
    }else{
      $destTablePrefix = $wpdb->base_prefix . $blogid . '_';
    }
    $optionsToRestore = array( 'siteurl' , 'home' , 'upload_path' );
    if( $opt['preserveBlogName'] )
      $optionsToRestore[] = 'blogname';
    $res3 = $wpdb->get_results( $wpdb->prepare( "SELECT `option_name` , `option_value` FROM {$destTablePrefix}options WHERE option_name IN (\"" . implode( '","' , $optionsToRestore ) . '")' ) , ARRAY_A );
    if( !count( $res3 ) )
      self::ajaxError( 'We could not find the data we need for the blog you are trying to deploy to.' );
    $options = array();
    for( $i = 0 , $c = count( $res3 ) ; $i < $c ; $i++ ){
      $options[$res3[$i]['option_name']] = $res3[$i]['option_value'];
    }

    // Update the Media folder
    $files = self::mexec( "$rsync -r -d $dir" . "blogs.dir/* " . WP_CONTENT_DIR . "/blogs.dir/{$blogid}/" );
    
    $fh = fopen( $dir . 'deployData.txt' , 'r' );
    $deployData = fread( $fh , 100 );
    $depDat = explode( ':' , $deployData );
    $sourceTablePrefix = $depDat[0];
    if( !$sourceTablePrefix )
      self::ajaxError( "We could not read the table prefix from $dir/deployData.txt" );
    $dbuser = DB_USER;
    $dbpass = DB_PASSWORD;
    $dbhost = DB_HOST;
    $dbname = DB_NAME;
    $slurp1 = self::mexec( "cat *.sql | $mysql -u $dbuser -p$dbpass -h $dbhost $temporaryDatabase " , $dir );
    if( preg_match( '/\w+/' , $slurp1 ) )
      self::ajaxError( "We encountered an error importing the data files from snapshot $name into database $temporaryDatabase $dbuser:$dbpass@$dbhost. The error was: " . substr( $slurp1 , 0 , 1000 ) );
    if( !( $dbh = mysql_connect( $dbhost , $dbuser , $dbpass , true ) ) && mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    if( !mysql_select_db( $temporaryDatabase , $dbh ) )
      self::ajaxError( "Could not select temporary database $temporaryDatabase : " . mysql_error( $dbh ) );
    $curdbres = mysql_query( 'SELECT DATABASE()' , $dbh );
    $curdbrow = mysql_fetch_array( $curdbres , MYSQL_NUM );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $destSiteURL = $options['siteurl'];
    $res4 = mysql_query( "SELECT `option_value` FROM `{$sourceTablePrefix}options` WHERE `option_name` = 'siteurl'" , $dbh );
    if( mysql_error( $dbh ) ){
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    if( !$res4 )
      self::ajaxError( 'We could not get the siteurl from the database we are about to deploy. That could mean that we could not create the DB or the import failed.' );
    $row = mysql_fetch_array( $res4 , MYSQL_ASSOC );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    if( !$row )
      self::ajaxError( 'We could not get the siteurl from the database we are about to deploy. That could mean that we could not create the DB or the import failed. (2)' );
    $sourceSiteURL = $row['option_value'];
    if( !$sourceSiteURL )
      self::ajaxError( 'We could not get the siteurl from the database we are about to deploy. That could mean that we could not create the DB or the import failed. (3)' );
    $destHost = preg_replace( '/^https?:\/\/([^\/]+).*$/i' , '$1' , $destSiteURL );
    $sourceHost = preg_replace( '/^https?:\/\/([^\/]+).*$/i' , '$1' , $sourceSiteURL );
    foreach( $options as $oname => $val ){
      mysql_query( "UPDATE {$sourceTablePrefix}options SET option_value = '" . mysql_real_escape_string( $val ) . "' WHERE option_name='" . mysql_real_escape_string( $oname ) . "'", $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    }
    $res5 = mysql_query( "SELECT id , post_content , guid FROM {$sourceTablePrefix}posts" , $dbh );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    while( $row = mysql_fetch_array( $res5 , MYSQL_ASSOC ) ){
      $content = preg_replace( '/(https?:\/\/)' . $sourceHost . '/i' , '$1' . $destHost , $row['post_content'] );
      $guid = preg_replace( '/(https?:\/\/)' . $sourceHost . '/i' , '$1' . $destHost , $row['guid'] );
      mysql_query( "UPDATE {$sourceTablePrefix}posts SET post_content = '" . mysql_real_escape_string( $content ) . "' , guid = '" . mysql_real_escape_string( $guid ) . "' WHERE ID=" . $row['ID'] , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    }

    mysql_query( "UPDATE {$temporaryDatabase}.{$sourceTablePrefix}options SET option_name = '{$destTablePrefix}user_roles' WHERE option_name = '{$sourceTablePrefix}user_roles'" , $dbh );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured while updating the user_roles option in the destination database: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );

    if( $leaveComments ){
     // Delete comments from DB we're deploying
      mysql_query( "DELETE FROM {$sourceTablePrefix}comments" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "DELETE FROM {$sourceTablePrefix}commentmeta" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      //Bring comments across from live (destination) DB
      mysql_query( "INSERT INTO {$temporaryDatabase}.{$sourceTablePrefix}comments SELECT * FROM {$dbname}.{$destTablePrefix}comments" , $dbh );
       if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO {$temporaryDatabase}.{$sourceTablePrefix}commentmeta SELECT * FROM {$dbname}.{$destTablePrefix}commentmeta" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );

     // Then remap comments to posts based on the "slug" which is the post_name
      $res6 = mysql_query( "SELECT dp.post_name AS destPostName , dp.ID AS destID , sp.post_name AS sourcePostName , sp.ID AS sourceID FROM {$dbname}.{$destTablePrefix}posts as dp , {$temporaryDatabase}.{$sourceTablePrefix}posts AS sp WHERE dp.post_name = sp.post_name" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      if( !$res6 )
        self::ajaxError( 'DB error creating maps betweeb post slugs: ' . mysql_error( $dbh ) );
      $pNameMap = array();
      while( $row = mysql_fetch_array( $res6 , MYSQL_ASSOC ) ){
        $pNameMap[$row['destID']] = $row['sourceID'];
      }

      $res10 = mysql_query( "SELECT comment_ID , comment_post_ID FROM {$sourceTablePrefix}comments" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      while( $row = mysql_fetch_array( $res10 , MYSQL_ASSOC ) ){
        if( array_key_exists( $row['comment_post_ID'] , $pNameMap ) ){
         // If a post exists in the source with the same slug as the destination, then associate the destination's comments with that post.
          mysql_query( "UPDATE {$sourceTablePrefix}comments set comment_post_ID = " . $pNameMap[$row['comment_post_ID']] . " WHERE comment_ID = " . $row['comment_ID'] , $dbh );
          if( mysql_error( $dbh ) )
            self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
        }else{
         // Otherwise delete the comment because it is associated with a post on the destination which does not exist in the source we're about to deploy
          mysql_query( "DELETE FROM {$sourceTablePrefix}comments WHERE comment_ID = " . $row['comment_ID'] , $dbh );
          if( mysql_error( $dbh ) )
            self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
        }
      }
      $res11 = mysql_query( "SELECT id FROM {$sourceTablePrefix}posts" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      while ($row = mysql_fetch_array($res11, MYSQL_ASSOC)) {
        $res12 = mysql_query( "SELECT COUNT(*) AS cnt FROM {$sourceTablePrefix}comments WHERE comment_post_ID = " . $row['ID'] , $dbh );
        if( mysql_error( $dbh ) )
          self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
        $row5 = mysql_fetch_array($res12, MYSQL_ASSOC);
        mysql_query( "UPDATE {$sourceTablePrefix}posts SET comment_count = " . $row5['cnt'] . " WHERE id = " . $row['ID'] , $dbh );
        if( mysql_error( $dbh ) )
          self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      }
    }
    if( !$backupDisabled ){
      if( !mysql_select_db( $dbname , $dbh ) )
        self::ajaxError( "Could not select database {$dbname} : " . mysql_error( $dbh ) );
      $curdbres = mysql_query( 'SELECT DATABASE()' , $dbh );
      $curdbrow = mysql_fetch_array( $curdbres , MYSQL_NUM );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      $res14 = mysql_query( 'SHOW TABLES' , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      $allTables = array();
      while( $row = mysql_fetch_array( $res14 , MYSQL_NUM ) ){
        array_push( $allTables , $row[0] );
      }
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      if( !mysql_select_db( $backupDatabase , $dbh ) )
        self::ajaxError("Could not select backup database {$backupDatabase} : " . mysql_error( $dbh ) );
      error_log( "BACKUPDB: {$backupDatabase}" );
      $curdbres = mysql_query( 'SELECT DATABASE()' , $dbh );
      $curdbrow = mysql_fetch_array( $curdbres , MYSQL_NUM );

      self::emptyDatabase( $backupDatabase , $dbh );
      foreach( $allTables as $t ){
       // We're taking across all tables including dep_ tables just so we have a backup. We won't deploy dep_ tables though
        mysql_query( "CREATE TABLE {$backupDatabase}.{$t} LIKE {$dbname}.{$t}" , $dbh );
        if( mysql_error( $dbh ) )
          self::ajaxError( "Could not create table {$t} in backup DB: " . mysql_error( $dbh ) );
        mysql_query( "INSERT INTO {$t} SELECT * FROM {$dbname}.{$t}" , $dbh );
        if( mysql_error( $dbh ) )
          self::ajaxError( "Could not copy table {$t} from {$dbname} database: " . mysql_error( $dbh ) );
      }
      mysql_query( "CREATE TABLE dep_backupdata ( name VARCHAR(20) NOT NULL , val VARCHAR(255) DEFAULT '')" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('blogid', '{$blogid}')" , $dbh );
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('prefix', '{$destTablePrefix}')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('deployTime', '" . microtime(true) . "')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('deployFrom', '{$sourceHost}')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('deployTo', '{$destHost}')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('snapshotName', '{$name}')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('projectID', '{$pid}')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      mysql_query( "INSERT INTO dep_backupdata VALUES ('projectName', '" . $proj['name'] . "')", $dbh);
      if( mysql_error( $dbh ) )
        self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    }

    if( !mysql_select_db( $temporaryDatabase , $dbh ) )
      self::ajaxError( "Could not select temporary database {$temporaryDatabase} : " . mysql_error( $dbh ) );
        $curdbres = mysql_query( 'SELECT DATABASE()' , $dbh );
        $curdbrow = mysql_fetch_array( $curdbres , MYSQL_NUM );

        $renames = array();
        foreach( self::$wpTables as $t ){
          array_push( $renames , "{$dbname}.{$destTablePrefix}{$t} TO {$temporaryDatabase}.old_{$t} , {$temporaryDatabase}.{$sourceTablePrefix}$t TO {$dbname}.{$destTablePrefix}{$t}" );
        }
        $stime = microtime( true );
        mysql_query( 'RENAME TABLE ' . implode( ', ', $renames ) , $dbh );
        $lockTime = sprintf( '%.4f' , microtime( true ) - $stime );
        if( mysql_error( $dbh ) )
          self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
        if( $temporaryDatabaseCreated ){
          mysql_query( "DROP DATABASE {$temporaryDatabase}" , $dbh );
        }else{
          self::emptyDatabase( $temporaryDatabase , $dbh );
        }
        if( mysql_error( $dbh ) )
          self::ajaxError( 'A database error occured trying to drop an old temporary database, but the deployment completed. Error was: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
        if( !$backupDisabled )
          self::deleteOldBackupDatabases();
        die( json_encode( array( 'ok' => 1 , 'lockTime' => $lockTime ) ) );
      }
  }

  public static function ajax_updateSnapDesc_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions();
    extract( $opt , EXTR_OVERWRITE );
    $pid = $_POST['projectid'];
    $snapname = $_POST['snapname'];
    $res = $wpdb->get_results( $wpdb->prepare( 'SELECT dir FROM dep_projects WHERE id = %d' , $pid ) , ARRAY_A );
    $dir = $res[0]['dir'];
    $fulldir = $datadir . $dir;
    $logOut = self::mexec( "$git checkout $snapname >/dev/null 2>&1 ; $git log -n 1 2>&1 ; $git checkout master >/dev/null 2>&1" , $fulldir );
    $logOut = preg_replace( '/^commit [0-9a-fA-F]+[\r\n]+/' , '' , $logOut );
    if( preg_match( '/fatal: bad default revision/' , $logOut ) )
      die( json_encode( array( 'desc' => '' ) ) );
    die( json_encode( array( 'desc' => $logOut ) ) );
  }

  public static function ajax_updateDeploySnapshot_callback(){
    self::checkPerms();
    global $wpdb;
    $opt = self::getOptions();
    extract( $opt , EXTR_OVERWRITE );
    $pid = $_POST['projectid'];
    $blogsTable = $wpdb->base_prefix . 'blogs';
    $blogs = $wpdb->get_results( $wpdb->prepare( "SELECT {$blogsTable}.blog_id AS blog_id , {$blogsTable}.domain AS domain , $blogsTable.path AS path FROM dep_members , $blogsTable WHERE dep_members.blog_id = {$blogsTable}.blog_id AND dep_members.project_id = %d AND dep_members.deleted = 0" , $pid ) , ARRAY_A );
    $res1 = $wpdb->get_results( $wpdb->prepare( "SELECT dir FROM dep_projects WHERE id = %d" , $pid ) , ARRAY_A );
    $dir = $datadir . $res1[0]['dir'];
    if( !is_dir( $dir ) )
      self::ajaxError( "The directory $dir for this project does not exist." );
    $bOut = self::mexec( "$git branch 2>&1" , $dir );
    $branches = preg_split( '/[\r\n\s\t\*]+/' , $bOut );
    $snapshots = array();
    for( $i = 0 , $c = count( $branches ) ; $i < $c ; $i++ ){
      if( preg_match( '/\w+/' , $branches[$i] ) ){
        $bname = $branches[$i];
        if( $bname=='master' )
          continue;
        $dateOut = self::mexec( "$git checkout $bname 2>&1; $git log -n 1 | grep Date 2>&1" , $dir );
        $m = '';
        if( preg_match( '/Date:\s+(.+)$/' , $dateOut , $m ) ){
          $ctime = strtotime($m[1]);
          $date = $m[1];
          array_push( $snapshots , array('name' => $branches[$i] , 'created' => $date , 'ctime' => $ctime ) );
        }
      }else{
        unset( $branches[$i] );
      }
    }
    if( count( $snapshots ) ){
      function ctimeSort( $b , $a ){
        if( $a['ctime']==$b['ctime'] )
          return 0;
        return ( $a['ctime'] < $b['ctime'] ? -1 : 1 );
      }
      usort( $snapshots , 'ctimeSort' );
    }
    die( json_encode( array( 'blogs' => $blogs , 'snapshots' => $snapshots ) ) );
  }

  public static function ajax_updateCreateSnapshot_callback(){
    self::checkPerms();
    global $wpdb;
    $pid = $_POST['projectid'];
    $blogsTable = $wpdb->base_prefix . 'blogs';
    $blogs = $wpdb->get_results( $wpdb->prepare( "SELECT {$blogsTable}.blog_id AS blog_id , {$blogsTable}.domain AS domain, {$blogsTable}.path AS path FROM dep_members , {$blogsTable} WHERE dep_members.blog_id = {$blogsTable}.blog_id AND dep_members.project_id = %d AND dep_members.deleted = 0" , $pid ) , ARRAY_A );
    die( json_encode( array( 'blogs' => $blogs ) ) );
  }

  public static function ajax_reloadProjects_callback(){
    self::checkPerms();
    global $wpdb;
    $blogsTable = $wpdb->base_prefix . 'blogs';
    $projects = $wpdb->get_results( $wpdb->prepare( 'SELECT id , name FROM dep_projects WHERE deleted = 0' ) , ARRAY_A );
    $allBlogs = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id , domain , path FROM $blogsTable ORDER BY domain ASC" ) , ARRAY_A );
    for( $i = 0 , $c = count( $projects ) ; $i < $c ; $i++ ){
      $mem = $wpdb->get_results($wpdb->prepare( "SELECT $blogsTable.blog_id AS blog_id , $blogsTable.domain AS domain , $blogsTable.path AS path FROM dep_members , $blogsTable WHERE dep_members.deleted=0 AND dep_members.project_id=%d AND dep_members.blog_id = $blogsTable.blog_id" , $projects[$i]['id'] ) , ARRAY_A );
      $projects[$i]['memberBlogs'] = $mem;
      $memids = array();
      $notSQL = '';
      if( count( $mem ) ){
        for( $j = 0 , $d = count( $mem ) ; $j < $d ; $j++ ){
          array_push( $memids , $mem[$j]['blog_id'] );
        }
        $notSQL = ' WHERE blog_id NOT IN (' . implode( ',' , $memids ) . ')';
      }
      $nonmem = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id , domain , path FROM {$blogsTable}{$notSQL} ORDER BY domain ASC" ) , ARRAY_A );
      $projects[$i]['nonmemberBlogs'] = $nonmem;
      $projects[$i]['numNonmembers'] = count( $nonmem );
    }
    die( json_encode( array( 'projects' => $projects ) ) );
  }

  public static function ajax_deploy_callback(){
    self::checkPerms();
    global $wpdb;
    $fromid = $_POST['deployFrom'];
    $toid = $_POST['deployTo'];
    $msgs = array();
    $fromBlog = $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id , domain , path FROM wp_blogs WHERE blog_id = %d' , $fromid ) , ARRAY_A );
    $toBlog = $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id , domain , path FROM wp_blogs WHERE blog_id = %d' , $toid ) , ARRAY_A );
    if( count( $fromBlog )!=1 )
      die( 'We could not find the blog you are deploying from.' );
    if( count( $toBlog )!=1 )
      die( 'We could not find the blog you are deploying to.');
    $fromPrefix = 'wp_';
    $toPrefix = 'wp_';

    if( $fromid!=1 )
      $fromPrefix .= $fromid . '_';
    if( $toid!=1 )
      $toPrefix .= $toid . '_';
    $t_fromPosts = $fromPrefix . 'posts';
    $t_toPosts = $toPrefix . 'posts';
    $fromPostTotal = $wpdb->get_results( $wpdb->prepare( "SELECT COUNT(*) AS cnt FROM $t_fromPosts WHERE post_status = 'publish'" , $fromid ) , ARRAY_A );
    $toPostTotal = $wpdb->get_results( $wpdb->prepare( "SELECT COUNT(*) AS cnt FROM $t_toPosts WHERE post_status = 'publish'" , $toid ) , ARRAY_A );
    $fromNewestPost = $wpdb->get_results( $wpdb->prepare( "SELECT post_title FROM $t_fromPosts WHERE post_status = 'publish' ORDER BY post_modified DESC LIMIT 1" , $fromid ) , ARRAY_A );
    $toNewestPost = $wpdb->get_results( $wpdb->prepare( "SELECT post_title FROM $t_toPosts WHERE post_status = 'publish' ORDER BY post_modified DESC LIMIT 1" , $toid ) , ARRAY_A );
    die( json_encode( array(
      'fromid'              => $fromid ,
      'toid'                => $toid ,
      'fromDomain'          => $fromBlog[0]['domain'] ,
      'fromPostTotal'       => $fromPostTotal[0]['cnt'] ,
      'fromNewestPostTitle' => $fromNewestPost[0]['post_title'] ,
      'toDomain'            => $toBlog[0]['domain'] ,
      'toPostTotal'         => $toPostTotal[0]['cnt'] ,
      'toNewestPostTitle'   => $toNewestPost[0]['post_title']
    ) ) );
  }

  public static function initHandler(){
    if( is_admin() ){
      wp_enqueue_script( 'jquery-templates' , plugin_dir_url( __FILE__ ) . 'js/jquery.tmpl.min.js' , array( 'jquery' ) );
      wp_enqueue_script( 'deploymint-js' , plugin_dir_url( __FILE__ ) . 'js/deploymint.js' , array( 'jquery' ) );
      wp_localize_script( 'deploymint-js' , 'DeployMintVars' , array( 'ajaxURL' => admin_url( 'admin-ajax.php' ) ) );
    }
  }

  public static function adminMenuHandler(){
    global $wpdb;
    extract( self::getOptions() , EXTR_OVERWRITE );
    $capability = ( is_multisite() ? 'manage_network' : 'manage_options' );
    add_submenu_page( 'DeployMint' , 'Manage Projects' , 'Manage Projects' , $capability , 'DeployMint' , 'deploymint::deploymintMenu' );
    add_menu_page( 'DeployMint' , 'DeployMint' , $capability , 'DeployMint' , 'deploymint::deploymintMenu' , WP_PLUGIN_URL . '/DeployMint/images/deployMintIcon.png' );
    $projects = $wpdb->get_results($wpdb->prepare( 'SELECT id , name FROM dep_projects WHERE deleted = 0' ) , ARRAY_A );
    for( $i = 0 , $c = count( $projects ) ; $i < $c ; $i++ ){
      add_submenu_page( 'DeployMint' , 'Proj: ' . $projects[$i]['name'] , 'Proj: ' . $projects[$i]['name'] , $capability , 'DeployMintProj' . $projects[$i]['id'] , 'deploymint::projectMenu' . $projects[$i]['id'] );
    }
    if( !$backupDisabled )
      add_submenu_page( 'DeployMint' , 'Emergency Revert' , 'Emergency Revert' , $capability , 'DeployMintBackout' , 'deploymint::undoLog' );
    add_submenu_page( 'DeployMint' , 'Options' , 'Options' , $capability , 'DeployMintOptions' , 'deploymint::myOptions' );
    add_submenu_page( 'DeployMint' , 'Help' , 'Help' , $capability , 'DeployMintHelp' , 'deploymint::help' );
  }

  public static function deploymintMenu(){
    if( !self::allOptionsSet() ){
      echo '<div class="wrap"><h2 class="depmintHead">Please visit the options page and configure all options</h2></div>';
      return;
    }
    include( 'views/deploymintHome.php' );
  }

  public static function help(){
    include( 'views/help.php' );
  }

  public static function myOptions(){
    $opt = self::getOptions();
    include( 'views/myOptions.php' );
  }

  private static function deleteOldBackupDatabases(){
    self::checkPerms();
    $opt = self::getOptions();
    extract( $opt , EXTR_OVERWRITE );
    if( $numBackups < 1 )
      return;
    $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true );
    mysql_select_db( DB_NAME , $dbh );
    $res1 = mysql_query( 'SHOW DATABASES' , $dbh );
    if( mysql_error( $dbh ) ){
      error_log( 'A database error occured: ' . mysql_error( $dbh ) );
      return;
    }
    $dbs = array();
    while( $row1 = mysql_fetch_array( $res1 , MYSQL_NUM ) ){
      $dbPrefix = ( $backupDatabase=='' ? 'depbak' : $backupDatabase );
      if( preg_match( '/^' . $dbPrefix . '__/' , $row1[0] ) ){
        $dbname = $row1[0];
        $res2 = mysql_query( "SELECT val FROM $dbname.dep_backupdata WHERE name = 'deployTime'" , $dbh );
        if( mysql_error( $dbh ) ){
          error_log( "Could not get deployment time for $dbname database" );
          return;
        }
        $row2 = mysql_fetch_array( $res2 , MYSQL_ASSOC );
        if( $row2 && $row2['val'] ){
          array_push( $dbs , array( 'dbname' => $dbname , 'deployTime' => $row2['val'] ) );
        }else{
          error_log( "Could not get deployment time for backup database $dbname" );
          return;
        }
      }
    }
    if( count( $dbs ) > $numBackups ){
      function deployTimeSort( $a , $b ){
        if( $a['deployTime'] == $b['deployTime'] )
          return 0;
        return ( $a['deployTime'] < $b['deployTime'] ? -1 : 1 );
      }
      usort( $snapshots , 'deployTimeSort' );
      for( $i = 0 , $c = ( count( $dbs ) - $numBackups ); $i < $c ; $i++ ){
        $db = $dbs[$i];
        $dbToDrop = $db['dbname'];
        mysql_query( "DROP DATABASE $dbToDrop" , $dbh );
        if( mysql_error( $dbh ) ){
          error_log( "Could not drop backup database $dbToDrop when deleting old backup databases:" . mysql_error( $dbh ) );
          return;
        }
      }
    }
  }

  public static function undoLog(){
    self::checkPerms();
    if( !self::allOptionsSet() ){
      echo '<div class="wrap"><h2 class="depmintHead">Please visit the options page and configure all options</h2></div>';
      return;
    }
    extract( self::getOptions() , EXTR_OVERWRITE );
    if( !( $dbh = mysql_connect( DB_HOST , DB_USER , DB_PASSWORD , true ) )
        || !mysql_select_db( DB_NAME , $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
    $res1 = mysql_query( 'SHOW DATABASES' , $dbh );
    if( mysql_error( $dbh ) )
      self::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );

    function readBackupData( $dbname , $dbh ){
      $res2 = mysql_query( "SELECT * FROM $dbname.dep_backupdata" , $dbh );
      if( mysql_error( $dbh ) )
        deploymint::ajaxError( 'A database error occured: ' . substr( mysql_error( $dbh ) , 0 , 200 ) );
      $dbData = array();
      while( $row2 = mysql_fetch_array( $res2 , MYSQL_ASSOC ) ){
        $dbData[$row2['name']] = $row2['val'];
      }
      $dbData['dbname'] = $dbname;
      $dbData['deployTimeH'] = date( 'l jS \of F Y h:i:s A' , sprintf( '%d' , $dbData['deployTime'] ) );
      return $dbData;
    }

    $dbs = array();
    if( $backupDatabase=='' || $numBackups!=1 ){
      while( $row1 = mysql_fetch_array( $res1 , MYSQL_NUM ) ){
        $dbPrefix = ( $backupDatabase=='' ? 'depbak' : $backupDatabase );
        if( preg_match( '/^' . $dbPrefix . '__/' , $row1[0] ) )
          array_push( $dbs , readBackupData( $row1[0] , $dbh ) );
      }

      function deployTimeSort( $b , $a ){
        if( $a['deployTime'] == $b['deployTime'] )
          return 0;
        return ( $a['deployTime'] < $b['deployTime'] ? -1 : 1 );
      }
      usort( $dbs , 'deployTimeSort' );

    }elseif( !$backupDisabled ){
      array_push( $dbs , readBackupData( $backupDatabase , $dbh ) );
    }

    include( 'views/undoLog.php' );
  }

  public static function ajaxError( $msg ){
    die( json_encode( array( 'err' => $msg ) ) );
  }

  private static function showMessage( $message , $errormsg = false ){
    $class = ( $errormsg ? 'error' : 'updated fade' );
    echo "<div id=\"message\" class=\"$class\"><p><strong>$message</strong></p></div>";
  }

  public static function msgDataDir(){
    deploymint::showMessage( 'You need to visit the options page for "DeployMint" and configure all options including a data directory that is writable by your web server.' , true );
  }

  public static function mexec( $cmd , $cwd = './' , $env = NULL ){
    $dspec = array(
      0 => array( 'pipe' , 'r' ) , //stdin
      1 => array( 'pipe' , 'w' ) , //stdout
      2 => array( 'pipe' , 'w' )   //stderr
    );
    $proc = proc_open( $cmd , $dspec , $pipes , $cwd );
    $stdout = stream_get_contents( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $ret = proc_close( $proc );
    return ( $stdout . $stderr );
  }

}
