<?php
/*
Plugin Name: SEO Meta Updater
Description: AI analizes product title, description, short description, generates 
    meta and updates fields. 
Version: 1.0
Author: Tomas Bancevicius
*/


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Hook into WordPress.
// add_action('init', 'seo_meta_updater_init');
// Register query var
function seo_meta_updater_query_vars($vars) {
    $vars[] = 'seo_meta_update';
    return $vars;
}
add_filter('query_vars', 'seo_meta_updater_query_vars');

// Check if our custom query var is set and call the main function
function seo_meta_updater_check_trigger() {
    $update_meta = get_query_var('seo_meta_update');
    if ($update_meta == 'run') {
        seo_meta_updater_init(); // Call your main function
    }
}
add_action('wp', 'seo_meta_updater_check_trigger');

// function seo_meta_updater_init() {
//     $args = array(
//         'post_type'      => 'product',
//         'posts_per_page' => -1,
//         'fields'         => 'ids',
//     );

//     $product_ids = get_posts($args);

//     foreach ($product_ids as $product_id) {
//         update_product_meta($product_id);
//         usleep(500000); // Delay to prevent API rate limits or timeouts.
//     }
// }

function seo_meta_updater_init()
{
    // Define the SKU you want to test with.
    $sku = '8401714';

    // Query to get the product ID by SKU.
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => 1,
        'meta_query' => array(
            array(
                'key' => '_sku',
                'value' => $sku,
                'compare' => '='
            )
        ),
        'fields' => 'ids'
    );

    $product_ids = get_posts($args);

    // Check if we have a product ID.
    if (!empty($product_ids)) {
        $product_id = $product_ids[0]; // Get the first (and should be only) product ID.
        update_product_meta($product_id);
    } else {
        error_log("No product found with SKU $sku");
    }
}



function update_product_meta($product_id)
{
    
    $product = wc_get_product($product_id);
    $title = $product->get_name();
    $sku = $product->get_sku();
    $description = $product->get_description();
    $short_description = $product->get_short_description();

    // Call OpenAI API 
    $ai_response = call_openai_api($title, $sku, $description, $short_description);

    if ($ai_response !== false) {
        update_post_meta($product_id, '_yoast_wpseo_focuskw', $ai_response['focus_keyphrase']);
        update_post_meta($product_id, '_yoast_wpseo_metadesc', $ai_response['meta_description']);
    } else {
        error_log('Failed to get AI response for product ID ' . $product_id);
    }
}


function call_openai_api($title, $sku, $description, $short_description)
{
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : 'key here'; 
    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $model = 'gpt-3.5-turbo'; // Replace this with the current available model if different

    $prompt = "Generate a focus keyphrase and meta description in JSON format for the following product: Title: $title, SKU: $sku, Description: $description, Short Description: $short_description";
    $body = json_encode([
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 150,
        'temperature' => 0
    ]);

    $response = wp_remote_post($endpoint, [
        'body'    => $body,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ]
    ]);

    if (is_wp_error($response)) {
        error_log('OpenAI call failed: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Check for errors or unexpected structure in response
    if (!isset($data['choices']) || empty($data['choices'])) {
        error_log('OpenAI response error or unexpected structure: ' . $body);
        return false;
    }

    // Remove the triple backticks and 'json' prefix from the content
    $contentStr = str_replace(['```json', '```'], '', $data['choices'][0]['message']['content']);

    // Attempt to decode the JSON response in the text
    $content = json_decode($contentStr, true);


    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Failed to decode JSON from OpenAI response: ' . $data['choices'][0]['message']['content']);
        return false;
    }

    if (!isset($content['focusKeyphrase'], $content['metaDescription'])) {
        error_log('Unexpected content in OpenAI API response: ' . $data['choices'][0]['message']['content']);
        return false;
    }

    return [
        'focus_keyphrase' => $content['focusKeyphrase'],
        'meta_description' => $content['metaDescription']
    ];
}

// http://test.local/?seo_meta_update=run