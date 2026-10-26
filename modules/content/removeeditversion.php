<?php
/**
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 * @package kernel
 */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$db = eZDB::instance();
$objectID = (int) $http->sessionVariable( "DiscardObjectID" );
$version = (int) $http->sessionVariable( "DiscardObjectVersion" );
$editLanguage = $http->sessionVariable( "DiscardObjectLanguage" );

$isConfirmed = false;
if ( $http->hasPostVariable( "ConfirmButton" ) )
    $isConfirmed = true;

if ( $http->hasSessionVariable( "DiscardConfirm" ) )
{
    $discardConfirm = $http->sessionVariable( "DiscardConfirm" );
    // The Discard action of content/edit sets the value for the redirect that follows, and it counts for that one
    // only. Left in the session, any later GET of this view discarded the version in DiscardObjectID and
    // DiscardObjectVersion without asking (a new draft can get the number of a discarded one).
    $http->removeSessionVariable( "DiscardConfirm" );
    if ( !$discardConfirm )
        $isConfirmed = true;
}

if ( $isConfirmed )
{
    $object = eZContentObject::fetch( $objectID );
    if ( $object === null )
        return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );

    $db->begin();

    if( $db->DatabaseName() == 'sqlite' )
    {
        $versionRows = $db->arrayQuery( "SELECT * FROM ezcontentobject_version WHERE version = $version AND contentobject_id = $objectID" );
     }
     else
     {
        $versionRows = $db->arrayQuery( "SELECT * FROM ezcontentobject_version WHERE version = $version AND contentobject_id = $objectID FOR UPDATE" );
    }

    if ( empty( $versionRows ) )
    {
        $db->commit(); // We haven't made any changes, but commit here to avoid affecting any outer transactions.
        return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
    }
    $versionObject = eZContentObjectVersion::fetch( $versionRows[0]['id'] );
    if ( is_object( $versionObject ) and
         in_array( $versionObject->attribute( 'status' ), array( eZContentObjectVersion::STATUS_DRAFT, eZContentObjectVersion::STATUS_INTERNAL_DRAFT ) ) )
    {
        // The kernel's edit check for this very version (editAccess(): the edit policy and the rules for another
        // user's draft), and for an object that was never published the create access under the parent of its
        // main node assignment (draftCreateAccess()), as the kernel's content/removeeditversion does. A kernel
        // without them: can_edit as before, and the first version rule without a fatal error when the object
        // has no node assignment.
        if ( method_exists( $object, 'editAccess' ) )
            $allowEdit = (bool)$object->editAccess( $versionObject );
        else
            $allowEdit = (bool)$object->attribute( 'can_edit' );

        if ( !$allowEdit )
        {
            if ( method_exists( $object, 'draftCreateAccess' ) )
                $allowEdit = (bool)$object->draftCreateAccess();
            else if ( $object->attribute( 'current_version' ) == 1 && !$object->attribute( 'status' ) )
            {
                $mainNode = eZNodeAssignment::fetchForObject( $object->attribute( 'id' ), 1 );
                $parentObj = isset( $mainNode[0] ) ? $mainNode[0]->attribute( 'parent_contentobject' ) : null;
                $allowEdit = $parentObj instanceof eZContentObject
                             && $parentObj->checkAccess( 'create', $object->attribute( 'contentclass_id' ), $parentObj->attribute( 'contentclass_id' ) );
            }

            if ( !$allowEdit )
            {
                $db->commit(); // We haven't made any changes, but commit here to avoid affecting any outer transactions.
                return $Module->handleError( eZError::KERNEL_ACCESS_DENIED, 'kernel', array( 'AccessList' => $object->accessList( 'edit' ) ) );
            }
        }

        $versionCount= $object->getVersionCount();
        $nodeID = $versionCount == 1 ? $versionObject->attribute( 'main_parent_node_id' ) : $object->attribute( 'main_node_id' );
        $versionObject->removeThis();
    }

    $db->commit();

    $hasRedirected = false;
    if ( $http->hasSessionVariable( 'RedirectIfDiscarded' ) )
    {
        // The page comes from the edit form (a posted RedirectIfDiscarded), so it is only followed when it passes
        // the rules of eZRedirectManager::returnURI() (no other host, no script address); else the redirect below
        // is used, as without one. A kernel without returnURI(): only a path inside the site.
        $discardURI = $http->sessionVariable( 'RedirectIfDiscarded' );
        $http->removeSessionVariable( 'RedirectIfDiscarded' );
        if ( method_exists( 'eZRedirectManager', 'returnURI' ) )
            $discardURI = eZRedirectManager::returnURI( $Module, false, $discardURI, array( 'session' => false ) );
        else if ( !is_string( $discardURI ) || !preg_match( '#^/(?![/\\\\])[^\\x00-\\x20]*$#D', $discardURI ) )
            $discardURI = false;
        if ( $discardURI !== false && $discardURI !== '' )
        {
            $Module->redirectTo( $discardURI );
            $hasRedirected = true;
        }
    }
    if ( $http->hasSessionVariable( 'ParentObject' ) && $http->sessionVariable( 'NewObjectID' ) == $objectID )
    {
        $parentArray = $http->sessionVariable( 'ParentObject' );
        $parentURL = $Module->redirectionURI( 'content', 'edit', $parentArray );
        $Module->redirectTo( $parentURL );
        $hasRedirected = true;
    }

    $http->removeSessionVariable( 'RedirectURIAfterPublish' );
    $http->removeSessionVariable( 'ParentObject' );
    $http->removeSessionVariable( 'NewObjectID' );

    if ( $hasRedirected )
    {
        return;
    }
    else if ( isset( $nodeID ) && $nodeID )
    {
        return $Module->redirectTo( '/content/view/full/' . $nodeID . '/' );
    }
    else
    {
        return eZRedirectManager::redirectTo( $Module, '/', true, array( 'content/edit' ) );
    }
}

if ( $http->hasPostVariable( "CancelButton" ) )
{
    $Module->redirectTo( '/content/edit/' . $objectID . '/' . $version . '/' );
}

$Module->setTitle( "Remove Editing Version" );


$tpl = eZTemplate::factory();
$tpl->setVariable( "Module", $Module );
$tpl->setVariable( "object_id", $objectID );
$tpl->setVariable( "object_version", $version );
$tpl->setVariable( "object_language", $editLanguage );
$Result = array();
$Result['content'] = $tpl->fetch( "design:content/removeeditversion.tpl" );
$Result['path'] = array( array( 'url' => '/content/removeeditversion/',
                                'text' => ezpI18n::tr( 'kernel/content', 'Remove editing version' ) ) );
?>
