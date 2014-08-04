<?php

/**
 * @file
 * Block related functions.
 */

/**
 * Implements hook_block_info().
 */
function share_light_block_info() {
  $blocks = array();

  $blocks['current_page'] = array(
    'info' => t('Share this page'),
    'cache' => DRUPAL_CACHE_PER_PAGE,
  );
  return $blocks;
}

/**
 * Implements hook_block_view().
 * @param string $path to the page - current url will be used if empty
 * @param array $options additional overrides for default options
 * @return renderable array
 */
function share_light_block_view($id, $link = NULL, $options = array()) {
  // Load options from the currently displayed node.
  $node = NULL;
  if (!($node = menu_get_object())) {
    $count = 0;
    $nid = preg_replace('/^.*\/(\d+)$/', '$1', $link, -1, $count);
    if ($count) {
      $node = node_load($nid);
    }
  }
  if ($node) {
    if ($item = _share_light_field_config_by_node($node, 'share_light')) {
      if ($item['toggle'] == '0') {
        return NULL;
      }
      if (empty($item['options']['subject'])) {
        unset($item['options']['subject']);
      }
      $options += $item['options'];
    }
  }

  // overrides based on the current page / shared page.
  if (empty($options['share_url']) && isset($_GET['share'])) {
    $options['share_url'] = $_GET['share'];
  }

  if (empty($options['share_url'])) {
    $options['share_url'] = current_path();
  }

  if ($link) {
    if (!is_array($link)) {
      $link = array('path' => $link);
    }
    $options['share_url'] = $link['path'];
  }

  // Global defaults
  $defaults = _share_light_defaults();
  $options += $defaults['options'];

  drupal_alter('share_light_options', $options);

  $link['path'] = $options['share_url'];
  $url = url($link['path'], $link + array('absolute' => TRUE));

  // try to use a default image url if no image url is saved (yet)
  $image_default_fields = variable_get('share_light_image_default_fields', array());
  if (!is_array($image_default_fields)) {
    $image_default_fields = array($image_default_fields);
  }
  // an fid of 0 gets saved if no image is selected
  // @TODO deal with the case somebody explicitly does not want to
  // share a default field
  if ((empty($options['image']) || $options['image']['fid'] == 0) && !empty($node->nid)) {
    foreach ($image_default_fields as $try_field) {
      if ($found_image = field_get_items('node', $node, $try_field)) {
        $options['image']['fid'] = $found_image[0]['fid'];
      }
    }
  }

  $options['image'] = file_load($options['image']['fid']);

  $links = array();
  // display the enabled channels
  $channels = variable_get('share_light_channels_enabled', _share_light_channels());
  foreach ($channels as $channel_name => $channel_value) {
    if ($channel_value) {
      if (!isset($options['advanced']['channel_'.$channel_name.'_toggle']) ||
        $options['advanced']['channel_'.$channel_name.'_toggle'] == 1) {
        $call_name = '_share_light_channel_' . $channel_name;
        if (function_exists($call_name)) {
          $channel_link = $call_name($url, $options, $link);
          if ($channel_link) {
            $links[$channel_name] = $channel_link;
          }
        }
      }
    }
  }

  foreach ($links as &$x) {
    $x['title'] = "<span>{$x['title']}</span>";
    $x['html'] = TRUE;
    $x['attributes']['target'] = '_blank';
  }

  $v['subject'] = $options['subject'];
  $v['content'] = array(
    '#theme' => 'links',
    '#links' => $links,
    '#attributes' => array('class' => array('share-light')),
  );
  return $v;
}

function _share_light_channel_facebook($url, $options, $link) {
  return array(
    'title' => 'Facebook',
    'href' => 'https://www.facebook.com/sharer.php',
    'query' => array('u' => urlencode($url)),
    'attributes' => array('title' => t('Share this via Facebook!'))
  );
}
function _share_light_channel_twitter($url, $options, $link) {
  $text = isset($options['advanced']['channel_twitter_text']) ? $options['advanced']['channel_twitter_text'] : '';
  return array(
    'title' => 'Twitter',
    'href' => 'http://twitter.com/share',
    'query' => array('text' => $text, 'url' => $url),
    'attributes' => array('title' => t('Share this via Twitter!'))
  );
}
function _share_light_channel_pinterest($url, $options, $link) {
  $text = isset($options['advanced']['channel_pinterest_text']) ? $options['advanced']['channel_pinterest_text'] : '';

  // get the url from the media object
  $media_url = '';
  if (!empty($options['image']->type)) {
    if ($options['image']->type == 'image' && ($image = image_load($options['image']->uri))) {
      $media_url = file_create_url($image->source);
      return array(
        'title' => 'Pinterest',
        'href' => 'http://www.pinterest.com/pin/create/button/',
        'query' => array('url' => $url, 'media' => $media_url, 'description' => $text),
        'attributes' => array('title' => t('Share this via Pinterest!'))
      );
    }
  }
}

function _share_light_channel_email($url, $options, $link) {
  $query['path'] = $link['path'];
  if (isset($link['query'])) {
    $query['query'] = $link['query'];
  }
  $parts = explode('/', $query['path']);
  if (count($parts) == 2 && $parts[0] == 'node' && is_numeric($parts[1])) {
    unset($query['path']);
    return array(
      'title' => 'E-Mail',
      'href' => $link['path'] . '/share',
      'query' => $query,
      'attributes' => array('title' => t('Share this via E-Mail!'))
    );
  } else {
    // current node == thank you page node (this is the nid we need
    // at /share-via-email
    if ($node = menu_get_object()) {
      unset($query['path']);
      return array(
        'title' => 'E-Mail',
        'href' => 'share-via-email',
        'query' => array('nid' => $node->nid),
        'attributes' => array('title' => t('Share this via E-Mail!'))
      );
    }
  }
}
