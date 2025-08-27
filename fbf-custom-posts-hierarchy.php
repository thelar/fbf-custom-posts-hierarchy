<?php
/*
Plugin Name: 4x4 Custom Posts Hierarchy
Plugin URI:
Description: Add page attributes to posts and support hiearchichal
Author: Kevin Price-Ward
Version: 1.0.0
Author URI: https://4x4tyres.co.uk
*/


add_action('registered_post_type', 'make_posts_hierarchical', 99, 2);

/**
 * Ensure posts post type is hierarchal and allows page attributes
 *
 * Initial Setup - Runs after each post type is registered
 */
function make_posts_hierarchical($post_type, $pto)
{

    // Return, if not post type posts
    if ($post_type != 'post') return;

    // access $wp_post_types global variable
    global $wp_post_types;

    // Set post type "post" to be hierarchical
    $wp_post_types['post']->hierarchical = 1;

    // Add page attributes to post backend
    // This adds the box to set up parent and menu order on edit posts.
    add_post_type_support('post', 'page-attributes');
}

/**
 *
 * Edit View of Permalink
 *
 * This affects editing permalinks, and $permalink is an array [template, replacement]
 * where replacement is the post_name and template has %postname% in it.
 *
 **/
add_filter('get_sample_permalink', function ($permalink, $post_id, $title, $name, $post) {
    if ($post->post_type != 'post' || !$post->post_parent) {
        return $permalink;
    }

    // Deconstruct the permalink parts
    $template_permalink = current($permalink);
    $replacement_permalink = next($permalink);

    // Find string
    $postname_string = '%postname%';
    $altered_template_with_parent_slug = get_path_from_post_id($post->ID, $postname_string);
    $new_template = str_replace("/$postname_string/", "/$altered_template_with_parent_slug/", $template_permalink);

    $new_permalink = [$new_template, $replacement_permalink];

    return $new_permalink;
}, 99, 5);

/**
 * Alter the link to the post
 *
 * This affects get_permalink, the_permalink etc.
 * This will be the target of the edit permalink link too.
 *
 * Note: only fires on "post" post types.
 */
add_filter('post_link', function ($post_link, $post, $leavename) {

    if ($post->post_type != 'post' || !$post->post_parent || $post->post_status == 'draft') {
        return $post_link;
    }

    // this filter can be applied when we want the templating for slug and also when we don't.
    // so check if the templating is there and continue to support it if so.
    $post_slug = stristr($post_link, '%postname%') ? '%postname%' : $post->post_name;
    $path = get_path_from_post_id($post->ID, $post_slug);

    return home_url($path);
}, 99, 3);

/**
 * Before getting posts
 *
 * Has to do with routing... adjusts the main query settings
 *
 */
add_action('pre_get_posts', function ($query) {
    global $wp_query;

    $original_query = $query;
    $uri_with_query_string = $_SERVER['REQUEST_URI']??'';
    $query_string = $_SERVER['QUERY_STRING']??'';
    $uri = str_replace('?' . $query_string, '', $uri_with_query_string);

    // Do not do this post check all the time
    if ($uri != '/' && $query->is_main_query() && !is_admin()) {

        $post = get_post_from_uri($uri);

        if (!$post) {
            return $original_query;
        }

        // pretty high confidence that we need to override the query.
        $query->query_vars['post_type'] = ['post'];
        $query->is_home    = false;
        $query->is_attachment = false;
        $query->is_page    = true;
        $query->is_single  = true;
        $query->is_404     = false;
        $query->queried_object_id = $post->ID;
        $query->set('page_id', $post->ID);

        $wp_query = $query;

        return $query;
    }

    return $wp_query;
}, 0);

add_filter('preview_post_link', 'preview_redirect_fix');

function preview_redirect_fix($url)
{
    global $post;

    if ($post->post_status == 'draft') {

        $pieces = (object) parse_url($url);
        $url = implode('', [
            $pieces->scheme,
            '://',
            $pieces->host . '/index.php?',
            $pieces->query
        ]);
    }

    return $url;
}

function get_path_from_post_id($pid, $current_slug = '')
{
    $pid_original = $pid;
    $slugs = [];
    while (!empty($pid)) {
        // Load the post for pid
        $p = get_post($pid);
        // Allow the original post to have a different name (useful for templating %postname% in permalink preview)
        $slugs[] = $pid == $pid_original && !empty($current_slug)
            ? $current_slug : $p->post_name;
        // Setup parent post id as new pid
        $pid = $p->post_parent;
    }

    return implode('/', array_reverse($slugs));
}

function get_post_from_uri($uri)
{
    global $wpdb;

    $basename = basename($uri);
    $depth = count(explode('/', trim($uri, '/')));

    // This inner query says "go get all posts - (ID, SLUG, PARENT_ID) - where slug = (last part of url)"
    $baseQuery = sprintf("select id, post_name as p1_slug, post_parent as p1_parent
      from $wpdb->posts where post_type = '%s' and post_name = '%s'", 'post', $wpdb->_real_escape($basename));

    // We will use concat to make slugs out of the results!
    // We will array_reverse the concats and implode with '/' to make the slug.
    $concats = [];
    $concats[] = "IFNULL(p1_slug, '')";

    // initialize our SQL string with the base query
    $sql = $baseQuery;

    // We will do 1 more depth level than we need to confirm the slug would not lazy match
    // This for loop builds inside out.
    for ($c = 1; $c < $depth + 2; $c++) {
        $d = $c;
        $p = $c + 1;

        $pre = "select d{$d}.*, p{$p}.post_name as p{$p}_slug, p{$p}.post_parent as p{$p}_parent from (";
        $suf = ") as d{$d} left join $wpdb->posts p{$p} on p{$p}.id = d{$d}.p{$c}_parent";

        $sql = $pre . $sql . $suf;

        $concats[] = sprintf("IFNULL(p{$p}_slug,'')");
    }

    $trimmedUri = trim($uri, '/');
    $concatSql = implode(", '/',", array_reverse($concats));
    $finalSql = "select * from (select TRIM(BOTH '/' FROM
    concat($concatSql)) as slug, id from ($sql) as d{$c}) as all_slugs
    where slug = '$trimmedUri';";

    $result = $wpdb->get_results($finalSql);
    if (empty($result) || !($post = current($result))) {
        return false;
    }

    return get_post($post->id);
}
