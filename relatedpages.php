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

        $cache_id = md5('relatedpages' . $page->path() . $pages->getPagesCacheId());
        $cached = $cache->fetch($cache_id);

        if ($cached !== false) {
            $this->related_pages = $cached;
            $debugger->addMessage('RelatedPages Plugin cache hit.');
            return;
        }

        // Resolve the candidate collection. Routes come from the page index, so
        // this does not load the body of every page on the site.
        $collection = $page->collection($config['filter']);

        // Related pages only apply on pages inside the filtered collection
        // (typically blog posts). Decide that from the collection keys alone — no
        // page content is loaded — so ordinary pages (home, listings, etc.) skip
        // all the matching work below. Cache the empty result so those pages don't
        // rebuild this on every request.
        if (!empty($config['page_in_filter'])) {
            $in_filter = array_key_exists($page->path(), $collection->toArray());
            // A current page whose own type is excluded never shows related pages.
            if ($in_filter && $this->isExcludedType($page->header(), $config)) {
                $in_filter = false;
            }
            if (!$in_filter) {
                $this->related_pages = [];
                $cache->save($cache_id, $this->related_pages);
                return;
            }
        }

        // reset array
        $this->related_pages = [];
        $debugger->addMessage('RelatedPages Plugin cache miss. Rebuilding...');

        {
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

                // Content-based scoring needs every candidate's text, so it must
                // scan the whole collection. When only taxonomy-to-taxonomy scoring
                // is active, pull the candidates that share a taxonomy value from
                // the taxonomy index instead — that uses targeted index lookups
                // when available and only loads pages that can actually match.
                $needs_full_scan = $process_taxonomy2content || $process_content;
                $candidates = $needs_full_scan
                    ? $collection
                    : $this->candidatesFromTaxonomy($collection, $config, $page_taxonomies);

                foreach ($candidates as $item) {
                    if ($page === $item) {
                        continue;
                    }

                    // Skip pages whose type is excluded so they never rank.
                    if ($this->isExcludedType($item->header(), $config)) {
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
                                            $score += $score_scale[max(array_keys($score_scale))];
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
                                        $score += $score_scale[max(array_keys($score_scale))];
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
                        similar_text((string) $page->rawMarkdown(), (string) $item->rawMarkdown(), $score);
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
        }

    }

    /**
     * True when the page header carries a type listed in the filter's excluded_types.
     */
    protected function isExcludedType($header, $config)
    {
        return property_exists($header, 'type') &&
            array_key_exists('filter', $config) &&
            array_key_exists('excluded_types', $config['filter']) &&
            in_array($header->type, $config['filter']['excluded_types']);
    }

    /**
     * Candidate pages that share a taxonomy value with the current page, pulled
     * from the taxonomy index and scoped to the filtered collection. Falls back to
     * the full collection when the page has none of the configured taxonomy values.
     */
    protected function candidatesFromTaxonomy($collection, $config, array $page_taxonomies)
    {
        $taxonomy_list = $config['taxonomy_match']['taxonomy'];
        if (!\is_array($taxonomy_list)) {
            $taxonomy_list = array($taxonomy_list);
        }

        $query = [];
        foreach ($taxonomy_list as $taxonomy) {
            if (!empty($page_taxonomies[$taxonomy])) {
                $query[$taxonomy] = $page_taxonomies[$taxonomy];
            }
        }

        // No taxonomy values to match on: nothing can score, so score nothing.
        if (!$query) {
            return new \Grav\Common\Page\Collection();
        }

        /** @var \Grav\Common\Taxonomy $taxonomy_map */
        $taxonomy_map = $this->grav['taxonomy'];
        $shared = $taxonomy_map->findTaxonomy($query, 'or');

        // Keep only pages that are also in the configured filter collection.
        $scoped = array_intersect_key($shared->toArray(), $collection->toArray());

        return new \Grav\Common\Page\Collection($scoped);
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
            $count += substr_count(strtolower((string) $haystack), strtolower((string) $substring));
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
