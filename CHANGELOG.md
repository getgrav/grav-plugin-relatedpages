# v1.2.4
## 12/06/2022

1. [](#bugfix)
    * Fix broken "page in collection" test that was causing plugin not to function 

# v1.2.3
## 01/22/2021

1. [](#improved)
    * Fixed caching that was broken for ever!!!

# v1.2.2
## 04/15/2019

1. [](#new)
    * Requires Grav 1.5.0
1. [](#improved)
    * Updated type hints from `Page` to `PageInterface`
    * Fixed ordering of the related pages with same score

# v1.2.1
## 01/08/2018

1. [](#improved)
    * Languages updates
1. [](#bugfix)
  * Fixed deprecated YAML syntax in `relatedpages.yaml` config file

# v1.2.0
## 08/21/2018

1. [](#new)
    * Added support for multiple taxonomies [#12](https://github.com/getgrav/grav-plugin-relatedpages/pull/12)
1. [](#improved)
    * Languages updates
    
# v1.1.4
## 07/18/2016

1. [](#improved)
    * Switched to `Page::rawMarkdown()` rather than `Page::content()` for increased performance.
1. [](#bugfix)    
    * Changed from `array_intersect_assoc` to `array_intersect` for broken tag-tag matching.

# v1.1.3
## 07/14/2016

1. [](#improved)
    * Added french

# v1.1.2
## 05/03/2016

1. [](#new)
    * Added Romanian and German translations

# v1.1.1
## 01/15/2016

1. [](#improved)
    * Disabled content-to-content matching by default (performance hit)
    * Small refactor

# v1.1.0
## 09/11/2015

1. [](#improved)
    * Added blueprints for admin compatibility
   
# v1.0.3
## 12/04/2015

1. [](#new)
    * ChangeLog started...
