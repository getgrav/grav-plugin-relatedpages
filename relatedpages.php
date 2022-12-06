<?php

namespace Grav\Plugin;

use Grav\Common\Cache;
use Grav\Common\Config\Config;
use Grav\Common\Debugger;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;

class RelatedPagesPlugin extends Plugin
{
    protected $related_pages = [];

    /** @var Config $config */
    protected $config;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize configuration
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $this->config = $this->grav['config']->get('plugins.relatedpages');

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onPageInitialized' => ['onPageInitialized', 0]
        ]);
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Process
     *
     */
    public function onPageInitialized()
    {
        /** @var Cache $cache */
        $cache = $this->grav['cache'];
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        /** @var PageInterface $page */
        $page = $this->grav['page'];
        /** @var Debugger $debugger */
        $debugger = $this->grav['debugger'];

        $config = $this->config;


        $this->enable([
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ]);

        $cache_id = md5('relatedpages' . $page->path() . $cache->getKey());
        $this->related_pages = $cache->fetch($cache_id);

        if ($this->related_pages === false) {

            // get all the pages
            $collection = $page->collection($config['filter']);

            //If the header of a page has a type and it is included in the excluded types remove it from the collection
            foreach ($collection as $pageKey) {
                $header = $pageKey->header();
                if (
                    property_exists($header, 'type') &&
                    array_key_exists('filter', $config) &&
                    array_key_exists('excluded_types', $config['filter']) &&
                    in_array($header->type, $config['filter']['excluded_types'])
                ) {
                    $collection->remove($pageKey);
                }
            }

            // perform check if page must be in filter values
            if ($config['page_in_filter'] && !array_key_exists($page->path(), $collection->toArray())) {
                return;
            }

            // reset array
            $this->related_pages = [];
            $debugger->addMessage('RelatedPages Plugin cache miss. Rebuilding...');

            // check for explicit related pages
            if ($config['explicit_pages']['process']) {
                $page_header = $page->header();
                if (isset($page_header->related_pages)) {
                    $explicit_pages = [];
                    $score = $config['explicit_pages']['score'];

                    foreach ($page_header->related_pages as $slug) {
                        $item = $pages->dispatch($slug);
                        if ($item) {
                            $explicit_pages[$item->path()] = $score;
                        }
                    }
                    $this->mergeRelatedPages($explicit_pages);
                }
            }

            // check for taxonomy and content
            $process_taxonomy2taxonomy = $config['taxonomy_match']['taxonomy_taxonomy']['process'];
            $process_taxonomy2content = $config['taxonomy_match']['taxonomy_content']['process'];
            $process_content = $config['content_match']['process'];

            if ($process_taxonomy2taxonomy || $process_taxonomy2content || $process_content) {
                $taxonomy_taxonomy_matches = [];
                $taxonomy_content_matches = [];
                $content_matches = [];
                $page_taxonomies = $page->taxonomy();

                foreach ($collection as $item) {
                    if ($page === $item) {
                        continue;
                    }

                    // count taxonomy to taxonomy matches
                    if ($process_taxonomy2taxonomy) {

                        // Check for multiple taxonomies.
                        $taxonomy_list = $config['taxonomy_match']['taxonomy'];
                        // Support the single value by converting it to array.
                        if (!\is_array($taxonomy_list)) {
                            $taxonomy_list = array($taxonomy_list);
                        }
                        $score_scale = $config['taxonomy_match']['taxonomy_taxonomy']['score_scale'];

                        $score = 0;
                        $has_matches = false;
                        foreach ($taxonomy_list as $taxonomy) {
                            if (isset($page_taxonomies[$taxonomy])) {
                                $page_taxonomy = $page_taxonomies[$taxonomy];
                                $item_taxonomies = $item->taxonomy();

                                if (isset($item_taxonomies[$taxonomy])) {
                                    $item_taxonomy = $item_taxonomies[$taxonomy];
                                    $count = count(array_intersect($page_taxonomy, $item_taxonomy));

                                    if ($count > 0) {
                                        if (array_key_exists($count, $score_scale)) {
                                            $score += $score_scale[$count];
                                        } else {
                                            $score += max(array_keys($score_scale));
                                        }

                                        $has_matches = true;
                                    }
                                }
                            }
                        }

                        if ($has_matches) {
                            $taxonomy_taxonomy_matches[$item->path()] = $score;
                        }
                    }

                    // count taxonomy to content matches
                    if ($process_taxonomy2content) {

                        // Check for multiple taxonomies.
                        $taxonomy_list = $config['taxonomy_match']['taxonomy'];
                        // Support the single value by converting it to array.
                        if (!is_array($taxonomy_list)) {
                            $taxonomy_list = array($taxonomy_list);
                        }
                        $score_scale = $config['taxonomy_match']['taxonomy_content']['score_scale'];

                        $score = 0;
                        $has_matches = false;
                        foreach ($taxonomy_list as $taxonomy) {
                            if (isset($page_taxonomies[$taxonomy])) {
                                $page_taxonomy = $page_taxonomies[$taxonomy];
                                $count = $this->substringCountArray($item->title() . ' ' . $item->rawMarkdown(), $page_taxonomy);

                                if ($count > 0) {
                                    if (array_key_exists($count, $score_scale)) {
                                        $score += $score_scale[$count];
                                    } else {
                                        $score += max(array_keys($score_scale));
                                    }

                                    $has_matches = true;
                                }
                            }
                        }

                        if ($has_matches) {
                            $taxonomy_content_matches[$item->path()] = $score;
                        }
                    }

                    // compute score of content to content matches
                    if ($process_content) {
                        similar_text($page->rawMarkdown(), $item->rawMarkdown(), $score);
                        $content_matches[$item->path()] = (int)$score;
                    }
                }
                $this->mergeRelatedPages($taxonomy_taxonomy_matches);
                $this->mergeRelatedPages($taxonomy_content_matches);
                $this->mergeRelatedPages($content_matches);
            }

            // Sort the resulting list
            arsort($this->related_pages, SORT_NUMERIC);

            // Shorten the resulting list if configured
            if ($config['limit'] > 0) {
                $this->related_pages = array_slice($this->related_pages, 0, $config['limit']);
            }

            $cache->save($cache_id, $this->related_pages);
        } else {
            $debugger->addMessage("RelatedPages Plugin cache hit.");
        }

    }

    /**
     * if enabled on this page, load the JS + CSS theme.
     */
    public function onTwigSiteVariables()
    {
        $this->grav['twig']->twig_vars['related_pages'] = $this->related_pages;
    }

    protected function substringCountArray($haystack, array $needle)
    {
        $count = 0;
        foreach ((array)$needle as $substring) {
            $count += substr_count(strtolower($haystack), strtolower($substring));
        }
        return $count;
    }

    protected function mergeRelatedPages(array $pages)
    {
        foreach ((array)$pages as $path => $score) {
            $page_exists = array_key_exists($path, $this->related_pages);
            if ($score > $this->config['score_threshold'] &&
                (!$page_exists || ($page_exists && $score > $this->related_pages[$path]))) {
                $this->related_pages[$path] = $score;
            }
        }
    }
}
