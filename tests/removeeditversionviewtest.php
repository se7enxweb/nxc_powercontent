<?php
/**
 * The view content/removeeditversion takes DiscardConfirm out of the session when it reads it, so the value the
 * Discard action of content/edit sets counts for the redirect that follows and no later request. Runs the view
 * against stand-ins for the kernel classes it calls, without the kernel and without a database.
 *
 * Run directly with:
 *   php tests/removeeditversionviewtest.php
 *
 *  DC-01 - DiscardConfirm=0 (no question): the version is discarded at once, and the value is gone from the session
 *  DC-02 - a later GET of the view with the discard data still in the session asks again, nothing is discarded
 *          (a new draft can have the number of the discarded one)
 *  DC-03 - DiscardConfirm=1: the confirmation page, nothing discarded; the posted ConfirmButton then discards
 *  DC-04 - no DiscardConfirm at all: the confirmation page
 */

$failures = 0;
function dcCheck( $ok, $message )
{
    global $failures;
    echo ( $ok ? 'PASS' : 'FAIL' ), ": $message\n";
    if ( !$ok )
        $failures++;
}

class DcLog
{
    public static $calls = array();
    public static function add( $what ) { self::$calls[] = $what; }
    public static function has( $prefix )
    {
        foreach ( self::$calls as $call )
            if ( strpos( $call, $prefix ) === 0 )
                return true;
        return false;
    }
    public static function text() { return implode( ' ', self::$calls ); }
}

class eZHTTPTool
{
    public static $post = array();
    public static $session = array();
    public static function instance() { return new self(); }
    public function hasPostVariable( $name ) { return array_key_exists( $name, self::$post ); }
    public function postVariable( $name ) { return self::$post[$name]; }
    public function hasSessionVariable( $name, $force = true ) { return array_key_exists( $name, self::$session ); }
    public function sessionVariable( $name ) { return isset( self::$session[$name] ) ? self::$session[$name] : null; }
    public function setSessionVariable( $name, $value ) { self::$session[$name] = $value; }
    public function removeSessionVariable( $name ) { unset( self::$session[$name] ); }
}

class eZDB
{
    public static function instance() { return new self(); }
    public function DatabaseName() { return 'mysql'; }
    public function begin() {}
    public function commit() {}
    public function arrayQuery( $sql )
    {
        return eZContentObjectVersion::$exists && preg_match( '/version = 3 AND contentobject_id = 57\b/', $sql )
            ? array( array( 'id' => 900 ) ) : array();
    }
}

class eZContentObject
{
    public static function fetch( $id ) { return $id == 57 ? new self() : null; }
    public function attribute( $name )
    {
        $values = array( 'can_edit' => true, 'main_node_id' => 60, 'id' => 57, 'current_version' => 3, 'status' => 1 );
        return $values[$name];
    }
    public function canEdit() { return true; }
    public function editAccess( $version = null ) { return true; }
    public function getVersionCount() { return 3; }
}

class eZContentObjectVersion
{
    const STATUS_DRAFT = 0;
    const STATUS_INTERNAL_DRAFT = 5;
    public static $exists = true;
    public static function fetch( $id ) { return new self(); }
    public function attribute( $name ) { return $name === 'status' ? self::STATUS_DRAFT : null; }
    public function removeThis() { DcLog::add( 'versionRemoved' ); self::$exists = false; }
}

class eZRedirectManager
{
    public static function redirectTo( $module, $default, $view = true, $disallowed = false ) { $module->redirectTo( $default ); return true; }
    public static function returnURI( $module, $default, $preferred = false, $options = array() ) { return $default; }
}

class eZError { const KERNEL_NOT_AVAILABLE = 3; const KERNEL_ACCESS_DENIED = 1; }

class eZTemplate
{
    public static function factory() { return new self(); }
    public function setVariable( $name, $value ) {}
    public function fetch( $template ) { DcLog::add( "template:$template" ); return ''; }
}

class ezpI18n { public static function tr( $context, $text ) { return $text; } }

class DcModule
{
    public $redirect = null;
    public function redirectTo( $uri ) { $this->redirect = $uri; return true; }
    public function redirectionURI( $module, $view, $params ) { return "/$module/$view"; }
    public function setTitle( $title ) {}
    public function handleError( $code, $type, $params = array() ) { DcLog::add( "error:$type:$code" ); return 'error'; }
}

function dcRun( array $post = array() )
{
    DcLog::$calls = array();
    eZHTTPTool::$post = $post;
    $module = new DcModule();
    $Params = array( 'Module' => $module );
    ( function() use ( $Params ) { include dirname( __DIR__ ) . '/modules/content/removeeditversion.php'; } )();
    return $module;
}

// what the Discard action of content/edit puts into the session before it redirects here
function dcDiscard( $confirm )
{
    eZHTTPTool::$session = array( 'DiscardObjectID' => 57, 'DiscardObjectVersion' => 3, 'DiscardObjectLanguage' => 'eng-GB' );
    if ( $confirm !== null )
        eZHTTPTool::$session['DiscardConfirm'] = $confirm;
    eZContentObjectVersion::$exists = true;
}

$asks = 'template:design:content/removeeditversion.tpl';

// DC-01
dcDiscard( false );
dcRun();
dcCheck( DcLog::has( 'versionRemoved' ) && !array_key_exists( 'DiscardConfirm', eZHTTPTool::$session ),
         'DC-01 DiscardConfirm=0: discarded at once, value gone from the session (' . DcLog::text() . ')' );

// DC-02
eZContentObjectVersion::$exists = true; // a new draft with the number of the discarded one
dcRun();
dcCheck( !DcLog::has( 'versionRemoved' ) && DcLog::has( $asks ),
         'DC-02 a later GET asks again, nothing discarded (' . DcLog::text() . ')' );

// DC-03
dcDiscard( true );
dcRun();
dcCheck( !DcLog::has( 'versionRemoved' ) && DcLog::has( $asks ) && !array_key_exists( 'DiscardConfirm', eZHTTPTool::$session ),
         'DC-03 DiscardConfirm=1: the confirmation page, nothing discarded (' . DcLog::text() . ')' );
dcRun( array( 'ConfirmButton' => '1' ) );
dcCheck( DcLog::has( 'versionRemoved' ), 'DC-03 the posted ConfirmButton discards (' . DcLog::text() . ')' );

// DC-04
dcDiscard( null );
dcRun();
dcCheck( !DcLog::has( 'versionRemoved' ) && DcLog::has( $asks ), 'DC-04 no DiscardConfirm: the confirmation page' );

echo $failures ? "FAIL: $failures\n" : "PASS: all\n";
exit( $failures ? 1 : 0 );
