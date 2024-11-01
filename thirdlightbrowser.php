<?php
/**
 * Plugin Name: Third Light Browser
 * Plugin URI: http://www.thirdlight.com
 * Description: This plugin allows users to use a Third Light server as an image library in WordPress.
 * Version: 0.1.0
 * Author: Third Light
 * Author URI: http://www.thirdlight.com
 * License: GPL2
 */

require_once("includes/imsapiclient.php");
require_once("includes/settings.php");

//create an instance of the settings in order to have them hooked in:
if( is_admin() ) { new ThirdLightBrowserSettings(); }


/**
 *
 * Code below adds the UI for interacting with the thirdlight browser, and injects
 * the relevant scripts:
 * 
 */

add_action('media_buttons_context', 'ThirdLightBrowserFrontend');
function ThirdLightBrowserFrontend($context) {

	//get some variables we're interested in using:
    $img = plugins_url('media/logo.png', __FILE__);
    $imgDisabled = plugins_url('media/logo_disabled.png', __FILE__);
    $IframeAppUrl = plugins_url("js/IMS.IframeApp.js", __FILE__);
    $IframeAppOverlayUrl = plugins_url("js/IMS.IframeAppOverlay.js", __FILE__);
    $options = get_option(ThirdLightBrowserSettings::NAME, ThirdLightBrowserSettings::defaultOptions());
    $output_formats = $options["output_formats"];
    $global_options = $options["global_options"];

    //check that URL is valid:
    $isValid = ThirdLightBrowserSettings::checkForValidUrl($global_options["site"]);

    //Build URL and options from wordpress db entries to pass into the JS:
    $url = $global_options["site"]."/apps/cmsbrowser/";
    $options = array(
    	"options" => array_merge(
//    		$global_options,
    		[],
    		array(
    			"metadata" => $global_options["showMetadata"] == "yes"? true : false,
    			"revisions" => $global_options["showRevisions"] == "yes"? true : false,
    			"hideCloseButton" => false,
    			"cropClasses" => array_values($output_formats),
    			"provideSFFUrl" => true
    		)
    	)
    );

	//get a sessionId based on the current options to pass to the client.
	//if this cannot be obtained, set isValid to a suitable error message.
	$sessionId = call_user_func(function() use ($global_options, &$options, &$isValid) {
		if($global_options["automaticLogin"] == "disabled") return false;
		global $current_user;
		$alertOnError = false;
		try
		{
			switch($global_options["automaticLogin"])
			{
				case "email":
				case "username":
					$userRef = $global_options["userRef"];
					$lookupType = $global_options["automaticLogin"];
					$alertOnError = true;
					break;
				case "username_dynamic":
					if(!is_user_logged_in())
					{
						return false;
					}
					get_currentuserinfo();
					$lookupType = "username";
					$userRef= $current_user->user_login;
					break;
				case "email_dynamic":
					if(!is_user_logged_in())
					{
						return false;
					}
					get_currentuserinfo();
					$lookupType = "email";
					$userRef= $current_user->user_email;
					break;
				default:
					// Not supported type
					$isValid = "The automatic login configuration provided in the Third Light Browser settings does not appear to be valid.";
					return false;
			}
			$API = new IMSApiClient($global_options["site"], $global_options["apikey"]);
			try
			{
			$userDetails = $API->Core_ImpersonateUser(array(
			                                          "userRef" => $userRef,
			                                          "lookupType" => $lookupType
			                                          ));
			}
			catch(IMSApiActionException $e)
					{
						if($alertOnError)
						{
				    		$isValid = "The automatic login name/email address provided in the Third Light Browser settings does not appear to be valid.";
						}
						else
						{
							$isValid = "Your account does not appear to be enabled for use with the Third Light Browser.";
						}
			    		return false;
			    	}
		}
		catch(IMSApiActionException $e)
		{
			$isValid = "The Third Light Browser does not appear to be configured correctly";
    		return false;
    	}

    	return $userDetails["sessionId"];
    });



    //if we got a sessionId, pass it to the client:
    if($sessionId){
    	$options["options"]["sessionId"] = $sessionId;
    }

    //inject the code where it's needed, nice and simple:
    ?>

    <div style="float: left">
    	<a href="#" class="button insert-media add_media" data-editor="content" title="Wordpress Media Library">
    		<span class="wp-media-buttons-icon"></span><?php _e("WordPress Media"); ?>
    	</a>
    </div>
	<div style="float: left">
		<a  href="#" id="thirdlight-add-media" class="button insert-thirdlight-media <?php echo ($isValid !== true? 'button-disabled' : 'button-enabled'); ?>" style="padding-left: .4em;">
			<span class="wp-media-buttons-icon" style="padding-right: 2px; vertical-align: text-bottom;"></span><?php _e("Third Light Media"); ?>		</a>
	</div>
	<style tyle="text/css">
	#wp-content-media-buttons>a:first-child { 
		display: none 
	}
	.insert-thirdlight-media .wp-media-buttons-icon{
		background: url('<?php echo $img ?>') no-repeat 0px 0px;
	}
	.insert-thirdlight-media.button-disabled .wp-media-buttons-icon{
		background: url('<?php echo $imgDisabled; ?>') no-repeat 0px 0px;
	}
	</style>
	<script type="text/javascript" src="<?php echo $IframeAppUrl; ?>"></script>
	<script type="text/javascript" src="<?php echo $IframeAppOverlayUrl; ?>"></script>

	<script type="text/javascript">
	document.addEventListener("DOMContentLoaded", function(){
		function launchApp(){

			//make crop details available to JS:
			var outputFormats = <?php echo json_encode($output_formats); ?>;

			//instantiate the browser, filling this iframe:
			var app = new IMS.IframeAppOverlay(
				"<?php echo $url; ?>",
				<?php echo json_encode($options); ?>
			);

			//react to a crop being chosen from the browser:
			app.on("cropChosen", function(cropDetails){

				//find the format we've chosen:
				var chosenFormat = null;
				for(var i = 0; i < outputFormats.length; i++){
					if(outputFormats[i].key == cropDetails.cropClass){
						chosenFormat = outputFormats[i];
					}
				}

				//create safe image tag html for output:
				var pasteThis = jQuery("<p>").append
				(
					jQuery("<img>").attr("src", cropDetails.urlDetails.url).attr("title", cropDetails.metadata.caption)
				).html();

				//send it to the editor. Putting it in a timeout, for some reason,
				//prevents IE from throwing an ACCESS DENIED error:
				setTimeout(function(){ wp.media.editor.insert(pasteThis); },0);

			});

		}
		//if app is active:
		jQuery("#thirdlight-add-media.button-enabled").on("click", launchApp);
		//else:	
		jQuery("#thirdlight-add-media").attr("title", "<?php echo ($isValid === true)? 'Third Light Media Library': $isValid; ?>");
	});
	</script>

	<?php
}

register_activation_hook(__FILE__, "activate");
function activate()
{
	if(version_compare(PHP_VERSION, '5.4', '<')) {
		deactivate_plugins( basename( __FILE__ ) );
		wp_die('<p>The <strong>Third Light Browser</strong> plugin requires PHP 5.4 or greater.</p>','Plugin Activation Error',  array( 'response'=>200, 'back_link'=>TRUE ) );
	}
	global $wp_version;
	if(version_compare( $wp_version, '3.4', '<' )) {
		deactivate_plugins( basename( __FILE__ ) );
		wp_die('<p>The <strong>Third Light Browser</strong> plugin requires WordPress 3.4 or greater.</p>','Plugin Activation Error',  array( 'response'=>200, 'back_link'=>TRUE ) );
	}

}
