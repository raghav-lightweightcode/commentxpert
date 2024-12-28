<?php
/*
Plugin Name: CommentXpert
Description: Adds an option for users to mark comments as private, viewable only by admins and the comment author.
Version: 1.0
Author: Raghav Chudasama
Text Domain: commentxpert
Domain Path: /languages
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Register the admin menu
function cmntxpt_add_admin_menu() {
    add_menu_page(
        __('CommentXpert Settings', 'commentxpert'),
        __('CommentXpert', 'commentxpert'),
        'manage_options',
        'commentxpert-settings',
        'cmntxpt_settings_page',
        'dashicons-admin-comments',
        80
    );
}
add_action('admin_menu', 'cmntxpt_add_admin_menu');

// Load the plugin's translated strings
function cmntxpt_load_textdomain() {
    load_plugin_textdomain('commentxpert', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'cmntxpt_load_textdomain');

// Deactivation hook - turn off private comments
function cmntxpt_deactivate() {
    update_option('cmntxpt_enable_private_comments', 0);
}
register_deactivation_hook(__FILE__, 'cmntxpt_deactivate');

// Uninstallation hook - remove the option
function cmntxpt_uninstall() {
    delete_option('cmntxpt_enable_private_comments');
}
register_uninstall_hook(__FILE__, 'cmntxpt_uninstall');

// Register the settings with sanitization
function cmntxpt_register_settings() {
    register_setting(
        'cmntxpt_settings',
        'cmntxpt_enable_private_comments',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'cmntxpt_sanitize_checkbox', // Sanitization callback
            'default'           => 0,
        )
    );
}
add_action('admin_init', 'cmntxpt_register_settings');

// Sanitization callback for the checkbox
function cmntxpt_sanitize_checkbox($input) {
    return (int) (bool) $input; // Ensure the value is 0 or 1
}

// Display the settings page
function cmntxpt_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('CommentXpert Settings', 'commentxpert'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('cmntxpt_settings');
            do_settings_sections('cmntxpt_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Enable Private Comments', 'commentxpert'); ?></th>
                    <td>
                        <input type="checkbox" name="cmntxpt_enable_private_comments" value="1" <?php checked(1, get_option('cmntxpt_enable_private_comments', 0)); ?> />
                        <input type="hidden" name="private_comment_nonce" value="<?php echo wp_kses_post(wp_create_nonce('private_comment_action')); ?>">
                        <label for="cmntxpt_enable_private_comments"><?php esc_html_e('Allow site users to mark comments as private', 'commentxpert'); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Check if private comments are enabled
function cmntxpt_is_private_comments_enabled() {
    return get_option('cmntxpt_enable_private_comments', 0) == 1;
}

// Check if comment registration is enabled
function cmntxpt_check_comment_registration() {
    return get_option('comment_registration') == 1;
}

// Display admin notice if comment registration is not enabled
function cmntxpt_check_comment_registration_notice() {
    if (cmntxpt_is_private_comments_enabled() && !cmntxpt_check_comment_registration()) {
        $link = esc_url(admin_url('options-discussion.php#comment_registration'));
        echo '<div class="notice notice-warning is-dismissible">';
        // Prepare the translation text with placeholders
        $translation_text = __('To enable private comments, please enable the <strong>"Users must be registered and logged in to comment"</strong> option in the', 'commentxpert');

        // Output the final paragraph
        echo '<p>' . wp_kses_post($translation_text) . wp_kses_post(sprintf('<a href="%s">Discussion Settings</a>', esc_url($link))) . '</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'cmntxpt_check_comment_registration_notice');

// Add the "Private Comment" checkbox above the submit button
if (cmntxpt_is_private_comments_enabled()) {

    if(cmntxpt_check_comment_registration()){
        // Add the "Private Comment" checkbox just above the Post Comment button
        function cmntxpt_private_cmnt_chkbox_above_submit($submit_field) {
            $private_comment_checkbox = '
                <p class="comment-form-private">
                    <input id="private_comment" name="private_comment" type="checkbox" />
                    <label for="private_comment">' . __('Post this comment as private', 'commentxpert') . '</label>
                    <input type="hidden" name="post_private_comment_nonce" value="'. wp_kses_post(wp_create_nonce('post_private_comment_action')) .'">
                </p>';
            return $private_comment_checkbox . $submit_field;
        }
        add_filter('comment_form_submit_field', 'cmntxpt_private_cmnt_chkbox_above_submit');

        // Save the private comment metadata
        function cmntxpt_save_private_comment_meta($comment_id) {
            if (isset($_POST['private_comment'])) {
                // Check if nonce is set before using it
                if (isset($_POST['post_private_comment_nonce'])) {
                    // Sanitize and unslash the nonce
                    $nonce = sanitize_text_field(wp_unslash($_POST['post_private_comment_nonce']));

                    // Verify the nonce
                    if (wp_verify_nonce($nonce, 'post_private_comment_action')) {
                        add_comment_meta($comment_id, 'private_comment', true);
                    } else {
                        // Nonce verification failed, handle the error
                        error_log('Nonce verification failed for private comment.');
                    }
                } else {
                    // Nonce not set, handle the error
                    error_log('Nonce is missing for private comment.');
                }
            }
        }
        add_action('comment_post', 'cmntxpt_save_private_comment_meta');

        // Filter comments to exclude private comments for non-admins and non-authors
        function cmntxpt_filter_private_comments($comments) {
            $current_user = wp_get_current_user();
            foreach ($comments as $key => $comment) {
                $private_comment = get_comment_meta($comment->comment_ID, 'private_comment', true);
                if ($private_comment && !current_user_can('administrator') && $comment->user_id != $current_user->ID) {
                    unset($comments[$key]);
                }
            }
            return $comments;
        }
        add_filter('the_comments', 'cmntxpt_filter_private_comments');

        // Modify the admin comments list to show private comments separately
        function cmntxpt_display_private_comments_in_admin($comments) {
            $private_comments = array_filter($comments, function($comment) {
                return get_comment_meta($comment->comment_ID, 'private_comment', true);
            });
            if ($private_comments) {
                echo '<h2>' . wp_kses_post(__('Private Comments Awaiting Approval', 'commentxpert')) . '</h2><ul>';
                foreach ($private_comments as $private_comment) {
                    echo '<li>' . wp_kses_post(get_comment_text($private_comment->comment_ID)) . ' - <a href="' . esc_url(admin_url('comment.php?action=approve&c=' . $private_comment->comment_ID)) . '">' . wp_kses_post(__('Approve', 'commentxpert')) . '</a></li>';
                }
                echo '</ul>';
            }
        }
        add_action('edit_comment', 'cmntxpt_display_private_comments_in_admin');

        // Approve comment with the option to make it public
		function cmntxpt_approve_private_comment($comment_id) {
			if (isset($_GET['approve']) && $_GET['approve'] === '1' && isset($_REQUEST['_wpnonce'])) {
				// Unslash and sanitize the nonce, then verify it
				$nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
				if (wp_verify_nonce($_REQUEST['_wpnonce'], 'approve-comment_' . $_GET['c'])) {
					// Approve the comment
					wp_set_comment_status($comment_id, 'approve');

					// Check if the comment should be made public
					if (isset($_POST['make_public'])) {
						delete_comment_meta($comment_id, 'private_comment');
					}
				} else {
					// Invalid nonce - handle the error (optional)
					wp_redirect(admin_url('edit-comments.php?nonce_failed=true'));
                    exit;
				}
			}
		}
		add_action('comment_post', 'cmntxpt_approve_private_comment');

        // Add approval options in the comment edit screen
        function cmntxpt_add_approval_options($comment) {
            if (get_comment_meta($comment->comment_ID, 'private_comment', true)) {
                echo '<p><label><input type="checkbox" name="make_public" value="1" /> ' . wp_kses_post(__('Make this comment public', 'commentxpert')) . '</label></p>';
            }
        }
        add_action('comment_edit_form', 'cmntxpt_add_approval_options');

        // Add a custom action button to make private comment public
        function cmntxpt_add_make_public_comment_action($actions, $comment) {
            if (get_comment_meta($comment->comment_ID, 'private_comment', true)) {
                $url = wp_nonce_url(admin_url('comment.php?action=make_public_comment&c=' . $comment->comment_ID), 'make_public_comment_' . $comment->comment_ID);
                $actions['make_public'] = '<a href="' . esc_url($url) . '">' . __('Make Public', 'commentxpert') . '</a>';
            } else {
                $url = wp_nonce_url(admin_url('comment.php?action=make_private_comment&c=' . $comment->comment_ID), 'make_private_comment_' . $comment->comment_ID);
                $actions['make_private'] = '<a href="' . esc_url($url) . '">' . __('Make Private', 'commentxpert') . '</a>';
            }
            return $actions;
        }
        add_filter('comment_row_actions', 'cmntxpt_add_make_public_comment_action', 10, 2);

        // Handle making the private comment public or vice versa
		function cmntxpt_handle_make_public_comment_action() {
			// Check if the action is 'make_public_comment' or 'make_private_comment'
			if (isset($_GET['action'])) {
				$comment_id = isset($_GET['c']) ? absint($_GET['c']) : 0;

				// Verify the nonce based on the action and comment ID
				$nonce_action = '';
				if ($_GET['action'] == 'make_public_comment') {
					$nonce_action = 'make_public_comment_' . $comment_id;
				} elseif ($_GET['action'] == 'make_private_comment') {
					$nonce_action = 'make_private_comment_' . $comment_id;
				}

				// Ensure the nonce is valid
				check_admin_referer($nonce_action);

                // Generate and pass nonce for the success message
				$redirect_nonce = wp_create_nonce('comment_status_change_nonce');

				if (current_user_can('edit_comment', $comment_id)) {
					if ($_GET['action'] == 'make_public_comment') {
						// Remove 'private_comment' meta to make the comment public
						delete_comment_meta($comment_id, 'private_comment');

                        $message = 1;
						// wp_redirect(admin_url('edit-comments.php?comment_status=approved&message=1&_wpnonce=' . $redirect_nonce));
                        wp_redirect(add_query_arg(['message' => $message, '_wpnonce' => $redirect_nonce], admin_url('edit-comments.php')));
						exit;

					} elseif ($_GET['action'] == 'make_private_comment') {
						// Add 'private_comment' meta to make the comment private
						add_comment_meta($comment_id, 'private_comment', true);

                        $message = 2;
						// wp_redirect(admin_url('edit-comments.php?comment_status=approved&message=2&_wpnonce=' . $redirect_nonce));
                        wp_redirect(add_query_arg(['message' => $message, '_wpnonce' => $redirect_nonce], admin_url('edit-comments.php')));
						exit;
					}
				}
			}
		}
		add_action('admin_init', 'cmntxpt_handle_make_public_comment_action');

        // Display a success notice after making the comment public or private
		function cmntxpt_show_make_public_comment_notice() {
			if (isset($_GET['message']) && isset($_REQUEST['_wpnonce'])) {
				// Verify and sanitize the nonce
				$nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
				if (wp_verify_nonce($nonce, 'comment_status_change_nonce')) {
                    $message = sanitize_text_field(wp_unslash($_GET['message']));
					if ($message == '1') {
						echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post(__('Comment made public successfully.', 'commentxpert')) . '</p></div>';
					} elseif ($message == '2') {
						echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post(__('Comment made private successfully.', 'commentxpert')) . '</p></div>';
					}
				}
			}
		}
		add_action('admin_notices', 'cmntxpt_show_make_public_comment_notice');

        // Register custom bulk actions
        function cmntxpt_register_bulk_actions($bulk_actions) {
            $bulk_actions['make_public_comments'] = __('Make Public', 'commentxpert');
            $bulk_actions['make_private_comments'] = __('Make Private', 'commentxpert');
            return $bulk_actions;
        }
        add_filter('bulk_actions-edit-comments', 'cmntxpt_register_bulk_actions');

        // Handle custom bulk actions
        function cmntxpt_handle_bulk_actions($redirect_to, $action, $comment_ids) {
            if ($action === 'make_public_comments') {
                foreach ($comment_ids as $comment_id) {
                    // Remove 'private_comment' meta to make it public
                    delete_comment_meta($comment_id, 'private_comment');
                }
                $redirect_to = add_query_arg('bulk_make_public', count($comment_ids), $redirect_to);
            }

            if ($action === 'make_private_comments') {
                foreach ($comment_ids as $comment_id) {
                    // Add 'private_comment' meta to make it private
                    update_comment_meta($comment_id, 'private_comment', true);
                }
                $redirect_to = add_query_arg('bulk_make_private', count($comment_ids), $redirect_to);
            }

            return $redirect_to;
        }
        add_filter('handle_bulk_actions-edit-comments', 'cmntxpt_handle_bulk_actions', 10, 3);

        // Display admin notices for bulk actions
        function cmntxpt_bulk_action_notices() {
            if (!empty($_REQUEST['bulk_make_public'])) {
                $count = intval($_REQUEST['bulk_make_public']);
                printf('<div id="message" class="updated notice notice-success is-dismissible"><p>' . _n('%s comments made public successfully.', '%s comments made public  successfully.', $count, 'commentxpert') . '</p></div>', $count);
            }

            if (!empty($_REQUEST['bulk_make_private'])) {
                $count = intval($_REQUEST['bulk_make_private']);
                printf('<div id="message" class="updated notice notice-success is-dismissible"><p>' . _n('%s comments made private successfully.', '%s comments made private successfully.', $count, 'commentxpert') . '</p></div>', $count);
            }
        }
        add_action('admin_notices', 'cmntxpt_bulk_action_notices');

        // Conditional comments count based on user permissions and including replies to private comments for authors/admins
        function cmntxpt_conditional_comment_count($count) {
            if (is_single()) { // Check if it's a single post
                global $post;
                $comments = get_comments(['post_id' => $post->ID, 'status' => 'approve']); // Get only approved comments

                $visible_count = 0;
                $current_user = wp_get_current_user();

                // Collect private comment IDs and their authors
                $private_comment_ids = [];
                $private_comment_authors = [];

                foreach ($comments as $comment) {
                    $private_comment = get_comment_meta($comment->comment_ID, 'private_comment', true);
                    if ($private_comment) {
                        $private_comment_ids[] = $comment->comment_ID; // Store the ID of private comments
                        $private_comment_authors[] = $comment->user_id; // Store the author ID of private comments
                    }
                }

                // Count comments based on user permissions
                foreach ($comments as $comment) {
                    $is_reply = $comment->comment_parent > 0; // Check if it's a reply

                    if (!$is_reply) {
                        // Count the top-level comment if it's public
                        if (!$private_comment || current_user_can('administrator') || in_array($current_user->ID, $private_comment_authors)) {
                            $visible_count++;
                        }
                    } else {
                        // If it's a reply, check if its parent is a private comment
                        if (in_array($comment->comment_parent, $private_comment_ids)) {
                            // Allow reply display to comment author or admin only
                            if (in_array($current_user->ID, $private_comment_authors) || current_user_can('administrator')) {
                                $visible_count++;
                            }
                        } else {
                            $visible_count++;
                        }
                    }
                }
                return $visible_count;
            }

            return $count;
        }
        add_filter('get_comments_number', 'cmntxpt_conditional_comment_count');
    }
}