<?php

namespace BootPress\Bootstrap;

trait Base
{
    protected function prefixClasses($base, array $prefix, $classes, $exclude_base = false)
    {
        if (!is_array($classes)) {
            $classes = explode(' ', $classes);
        }
        $classes = array_filter($classes);
        foreach ($classes as $key => $class) {
            if (in_array($class, $prefix)) {
                $classes[$key] = $base.'-'.$class;
            }
        }
        if ($exclude_base === false) {
            array_unshift($classes, $base);
        }

        return implode(' ', array_unique($classes));
    }

    protected function addClass($html, array $tags)
    {
        $rnr = array();
        foreach ($tags as $tag => $class) {
            $prefix = (is_array($class) && isset($class[2]) && is_array($class[1])) ? $class : false;
            if ($prefix) {
                $class = $class[2];
            }
            if (is_string($class) && !empty($class)) {
                $class = explode(' ', $class);
                preg_match_all('/(\<'.$tag.'([^\>]*)\>)/i', $html, $matches);
                foreach (array_unique($matches[0]) as $add) {
                    if ($this->firstTagAttributes($add, $match)) {
                        list($add, $tag, $attributes) = $match;
                        $merge = (isset($attributes['class'])) ? array_merge(explode(' ', $attributes['class']), $class) : $class;
                        if ($prefix) {
                            $prefix[2] = $merge;
                            $attributes['class'] = call_user_func_array(array($this, 'prefixClasses'), $prefix);
                        } else {
                            $attributes['class'] = implode(' ', array_unique(array_filter($merge)));
                        }
                        foreach ($attributes as $key => $value) {
                            $attributes[$key] = $key.'="'.$value.'"';
                        }
                        $rnr[$add] = '<'.$tag.' '.implode(' ', $attributes).'>';
                    }
                }
            }
        }

        return (!empty($rnr)) ? str_replace(array_keys($rnr), array_values($rnr), $html) : $html;
    }

    protected function firstTagAttributes($html, &$matches, $find = '<')
    {
        if (false !== $begin = strpos($html, $find)) {
            if (false !== $end = strpos($html, '>', $begin)) {
                $first = substr($html, $begin, $end - $begin + 1);
                $tag = trim(substr($first, 1, -1));
                $dom = new \DOMDocument();
                @$dom->loadHTML('<'.rtrim($tag.'/').' />');
                foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) {
                    $attributes = array();
                    foreach ($node->attributes as $attr) {
                        $attributes[$attr->nodeName] = $attr->nodeValue;
                    }
                    $matches = array($first, $node->nodeName, $attributes);
                    unset($dom);
                    break;
                }
            }
        }

        return isset($attributes) ? true : false;
    }
}
