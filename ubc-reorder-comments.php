<?php

/**
 * UBC Reorder Comments
 *
 * @package     ReorderComments
 * @author      Richard Tape
 * @copyright   2020 Richard Tape
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: UBC Reorder Comments
 * Plugin URI:  https://ctlt.ubc.ca
 * Description: Adds the ability to reorder comments based on several different, customizable, pieces of data.
 * Version:     0.1.0
 * Author:      Richard Tape, Kelvin Xu, UBC CTLT
 * Author URI:  https://ctlt.ubc.ca/
 * Text Domain: ubc-reorder-comments
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

add_filter( 'comments_template_query_args', 'comments_template_query_args__comments_like_filtering', 10, 1 );

/**
 * Alter the WP_Comment_Query to filter comments for this post based on the query arguments.
 *
 * @param array $comment_args - Current WP_Comment_Query arguments.
 * @return array modified query args.
 */
function comments_template_query_args__comments_like_filtering( $comment_args ) {

	// A type is required. If one isn't provided, just bail early.
	$filter_type = get_query_var( 'comment_filter_type' );
	$filter_type = sanitize_key( $filter_type );

	if ( ! $filter_type || empty( $filter_type ) ) {
		return $comment_args;
	}

	// If we have a type, we must provide a value, otherwise bail.
	$filter_value = get_query_var( 'comment_filter_value' );
	$filter_value = absint( $filter_value );

	if ( ! $filter_value || 0 === $filter_value ) {
		return $comment_args;
	}

	// We may have different types of filters. We'll normalize here.
	$comment_meta_key = '';

	switch ( $filter_type ) {

		case 'like':
			// $comment_meta_key = 'cld_like_count';
			$comment_meta_key = get_comment_like_rubric_meta_key();
			break;

		default:
			break;
	}

	if ( empty( $comment_meta_key ) ) {
		return $comment_args;
	}

	$filter_comparison = get_query_var( 'comment_filter_comparison' );

	// Sanitizing these things is tricky. So we'll set a very specific set of possibilities for
	// this value.
	$allowed_comparisons = array(
		'>',
		'<',
		'>=',
		'<=',
		'=',
		'!=',
		'gt',
		'lt',
		'gte',
		'lte',
		'e',
		'ne',
	);

	// Default to >= .
	if ( ! in_array( $filter_comparison, array_values( $allowed_comparisons ), true ) ) {
		$filter_comparison = '>=';
	}

	// Translate text versions into equivalents.
	switch ( $filter_comparison ) {
		case 'gt':
			$filter_comparison = '>';
			break;

		case 'lt':
			$filter_comparison = '<';
			break;

		case 'gte':
			$filter_comparison = '>=';
			break;

		case 'lte':
			$filter_comparison = '<=';
			break;

		case 'e':
			$filter_comparison = '=';
			break;

		case 'ne':
			$filter_comparison = '!=';
			break;

		default:
			$filter_comparison = '>=';
			break;
	}

	// Sanitize order and default to DESC.
	$filter_order = get_query_var( 'comment_filter_order' );
	$filter_order = sanitize_key( $filter_order );

	$filter_order = strtoupper( $filter_order );

	if ( 'ASC' !== $filter_order && 'DESC' !== $filter_order ) {
		$filter_order = 'DESC';
	}

	// Our modifications.
	$comment_args['meta_query'] = array( // phpcs:ignore
		array(
			'key'     => $comment_meta_key,
			'value'   => $filter_value,
			'compare' => $filter_comparison,
		),
	);

	$comment_args['orderby']  = 'meta_value_num';
	$comment_args['meta_key'] = $comment_meta_key; // phpcs:ignore

	$comment_args['order'] = $filter_order; // DESC = highest first.

	return $comment_args;

}//end comments_template_query_args__comments_like_filtering()



add_filter( 'query_vars', 'query_vars__add_comment_filtering_query_vars' );

/**
 * Add our comment filtering query vars.
 *
 * @param array $vars Current query vars.
 * @return array modified query vars containing our comment querying var.
 */
function query_vars__add_comment_filtering_query_vars( $vars ) {

	$vars[] = 'comment_filter_type'; // such as 'like'.
	$vars[] = 'comment_filter_value'; // such as '2'.
	$vars[] = 'comment_filter_comparison'; // such as '>='.
	$vars[] = 'comment_filter_order'; // such as 'ASC'.

	return $vars;

}//end query_vars__add_comment_filtering_query_vars()


add_filter( 'comments_array', 'comments_array__get_filtered_comment_count', 10, 2 );

/**
 * In order to change the title above the comments, which contains the number of comments,
 * which is incorrect when we filter out some comments in query_vars__add_comment_filtering_query_vars()
 * we need to hook in just after the query is run to enable us to count.
 *
 * @param array $comments Array of comments supplied to the comments template.
 * @param int   $post_id  Post ID.
 * @return array comments supplied.
 */
function comments_array__get_filtered_comment_count( $comments, $post_id ) {

	// Manipulate the current post.
	global $post;
	$post->comment_count = count( $comments );

	return $comments;

}//end comments_array__get_filtered_comment_count()

/**
 * The "Like" rubric is a post of the post type 'ubc_wp_vote_rubric' with a title of  'Upvote'.
 * That post has an ID and that ID is what forms the meta key for comment meta when someone
 * upvotes a comment.
 *
 * @return false|string False if we can't find the rubric. The string of the meta otherwise.
 */
function get_comment_like_rubric_meta_key() {

	$rubric = get_page_by_title( 'Upvote', 'OBJECT', 'ubc_wp_vote_rubric' );

	if ( ! $rubric ) {
		return false;
	}

	$rubric_id      = intval( $rubric->ID );

	$comment_like_rubric_meta_key = 'ubc_wp_vote_' . $rubric_id . '_total';

	return sanitize_key( $comment_like_rubric_meta_key );

}//end get_comment_like_rubric_meta_key()



add_action( 'wp-hybrid-clf_before_comment_list', 'before_comment_list__output_comment_filters' );

/**
 * The CLF v7 theme gives us a before_comment_list action in the comments.php template. Hybrid
 * converts this to be wp-hybrid-clf_before_comment_list
 *
 * We use this hook to output the filters interface for our comments.
 *
 * @return void
 */
function before_comment_list__output_comment_filters() {

	// Defaults.
	$filter_type       = 'like';
	$filter_comparison = 'gt';
	$filter_value      = '1';
	$filter_order      = 'asc';

	// Check if filters already set.
	if ( isset( $_POST['ubc_filter_comments_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['ubc_filter_comments_nonce'] ), 'ubc_filter_comments' ) ) {
		$filter_type       = wp_unslash( sanitize_key( $_POST['comment_filter_type'] ) ); //phpcs:ignore
		$filter_comparison = wp_unslash( sanitize_key( $_POST['comment_filter_comparison'] ) ); //phpcs:ignore
		$filter_value      = wp_unslash( absint( $_POST['comment_filter_value'] ) ); //phpcs:ignore
		$filter_order      = wp_unslash( sanitize_key( $_POST['comment_filter_order'] ) ); //phpcs:ignore
	}

	?>
	<form action="<?php echo esc_url( get_the_permalink() ); ?>" method="POST" id="reorder-comments-filters">
	<label for="comment_filter_type">Filter Responses By:</label>

	<select name="comment_filter_type" id="comment_filter_type">
		<option value="" <?php selected( $filter_type, '' ); ?>>-- Please Choose an Option --</option>
		<option value="like" <?php selected( $filter_type, 'like' ); ?>>Number of Thumbs Up</option>
	</select>

	<select name="comment_filter_comparison" id="comment_filter_comparison">
		<option value="" <?php selected( $filter_comparison, '' ); ?>>-- Please Choose an Option --</option>
		<option value="gt" <?php selected( $filter_comparison, 'gt' ); ?>>></option>
		<option value="lt" <?php selected( $filter_comparison, 'lt' ); ?>><</option>
		<option value="e" <?php selected( $filter_comparison, 'e' ); ?>>=</option>
	</select>

	<input type="number" size="2" name="comment_filter_value" id="comment_filter_value" placeholder="1" value="<?php echo absint( $filter_value ); ?>" />

	<select name="comment_filter_order" id="comment_filter_order">
		<option value=""<?php selected( $filter_order, '' ); ?>>-- Please Choose an Option --</option>
		<option value="asc"<?php selected( $filter_order, 'asc' ); ?>>In Ascending Order</option>
		<option value="desc" <?php selected( $filter_order, 'desc' ); ?>>In Descending Order</option>
	</select>

	<?php wp_nonce_field( 'ubc_filter_comments', 'ubc_filter_comments_nonce' ); ?>
	<input type="submit" id="submit-comment-filter" value="Filter" />

	</form>
	<?php
}//end before_comment_list__output_comment_filters()


// add_action( 'init', 'init__check_for_comment_filters' );

/**
 * If our comments filters have been submitted, parse the filters.
 *
 * @return void
 */
function init__check_for_comment_filters() {

	if ( ! isset( $_POST['ubc_filter_comments_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ubc_filter_comments_nonce'] ), 'ubc_filter_comments' ) ) {
		return;
	}

	if ( ! isset( $_POST['comment_filter_type'] ) || '' === wp_unslash( sanitize_key( $_POST['comment_filter_type'] ) ) ) {
		return;
	}

	$filter_type       = wp_unslash( sanitize_key( $_POST['comment_filter_type'] ) ); //phpcs:ignore
	$filter_comparison = wp_unslash( sanitize_key( $_POST['comment_filter_comparison'] ) ); //phpcs:ignore
	$filter_value      = wp_unslash( absint( $_POST['comment_filter_value'] ) ); //phpcs:ignore
	$filter_order      = wp_unslash( sanitize_key( $_POST['comment_filter_order'] ) ); //phpcs:ignore

	file_put_contents( WP_CONTENT_DIR . '/debug.log', print_r( array( $filter_type, $filter_comparison, $filter_value, $filter_order ), true ), FILE_APPEND ); // phpcs:ignore


}//end init__check_for_comment_filters()


add_action( 'wp_enqueue_scripts', 'wp_enqueue_scripts__reorder_comments_styles' );

/**
 * Enqueue our stylesheet
 *
 * @return void
 */
function wp_enqueue_scripts__reorder_comments_styles() {

	if ( is_admin() ) {
		return;
	}

	if ( ! is_singular() ) {
		return;
	}

	wp_enqueue_style( 'ubc-reorder-comments', plugin_dir_url( __FILE__ ) . '/assets/css/ubc-reorder-comments.css', array(), '0.1.0' );

}//end wp_enqueue_scripts__reorder_comments_styles()
