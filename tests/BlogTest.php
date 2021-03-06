<?php

namespace BootPress\Tests;

use BootPress\Page\Component as Page;
use BootPress\Blog\Component as Blog;
use BootPress\Blog\Component as Theme;
use BootPress\Sitemap\Component as Sitemap;
use BootPress\Pagination\Component as Pagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

class BlogTest extends HTMLUnit_Framework_TestCase
{
    protected static $blog;
    protected static $config;
    protected static $folder;
    
    public static function tearDownAfterClass()
    {
        $dir = __DIR__.'/page/';
        foreach (array(
            $dir.'blog/content/undefined',
            $dir.'blog/smarty',
            $dir.'blog/themes',
            $dir.'blog/Blog.db',
            $dir.'blog/config.yml',
            $dir.'temp',
        ) as $target) {
            self::remove($target);
        }
    }

    public function testConstructorWithoutDir()
    {
        $request = Request::create('http://website.com/');
        $page = Page::html(array('dir' => __DIR__.'/page', 'suffix'=>'.html'), $request, 'overthrow');
        $folder = $page->dir('temp');
        self::remove($folder);
        
        $blog = new Blog($folder);
        $this->assertNull($blog->missing);
        $this->assertEquals($folder, $blog->folder);
        $this->assertEquals(0, $blog->query(array())); // the number of blog posts and pages
        $this->assertNull($blog->query(array('archives' => array(1, 2, 3)))); // should only be 2 values
        $this->assertNull($blog->query(array('authors' => array(1, 2, 3)))); // should be a string
        $this->assertNull($blog->query(array('tags' => array(1, 2, 3)))); // should be a string
        $this->assertNull($blog->query(array(
            'categories' => array('string'),
        ))); // should either be a string, or an array of id's
        $this->assertNull($blog->query(array(
            'categories' => '',
            'search' => 'term',
        ))); // the category doesn't exist
        $this->assertNull($blog->query(array(
            'categories' => '',
        ))); // the category doesn't exist
        
        $blog->db->connection()->close(); // releases Blog.db so it can be deleted
        unset($blog);
        self::remove($folder);
    }

    public function testConstructorAndDestructor()
    {
        $request = Request::create('http://website.com/');
        $page = Page::html(array('dir' => __DIR__.'/page', 'suffix' => '.html'), $request, 'overthrow');
        
        // Remove files that may be lingering from previous tests, and set up for another round
        self::remove($page->file('Sitemap.db'));
        
        // set irrelevant config values
        static::$config = $page->file('blog/config.yml');
        self::remove(static::$config);
        file_put_contents(static::$config, Yaml::dump(array(
            'authors' => array(
                'joe-bloggs' => array(
                    'thumb' => 'user.jpg',
                ),
                'anonymous' => 'anonymous',
            ),
            'categories' => array('unknown' => 'UnKnown'),
            'tags' => array('not-exists' => 'What are you doing?'),
        ), 3));
        $this->assertEquals(implode("\n", array(
            'authors:',
            '    joe-bloggs:',
            '        thumb: user.jpg',
            '    anonymous: anonymous',
            'categories:',
            '    unknown: UnKnown',
            'tags:',
            "    not-exists: 'What are you doing?'",
        )), trim(file_get_contents(static::$config)));
        
        static::$folder = $page->dir('blog/content');
        $unpublished = static::$folder.'category/unpublished-post/index.tpl';
        self::remove(dirname($unpublished));
        
        $db = $page->file('blog/Blog.db');
        self::remove($db);
        
        rename($page->dir('blog/content/category'), $page->dir('blog/content/Category'));
        $themes = $page->dir('blog/themes');
        self::remove($themes);

        // Test Blog constructor, properties, and destructor
        $blog = new Blog($page->dir('blog'));
        $this->assertInstanceOf('BootPress\Database\Component', $blog->db);
        $this->assertAttributeInstanceOf('BootPress\Blog\Theme', 'theme', $blog);
        $this->assertAttributeEquals($page->dir('blog'), 'folder', $blog);
        $this->assertEquals($page->url['base'].'blog.html', $page->url('blog'));
        $this->assertEquals($page->url['base'].'blog/listings.html', $page->url('blog', 'listings'));
        unset($blog);
    }

    public function testAboutPage()
    {
        $template = $this->blogPage('about.html');
        $file = static::$folder.'about/index.tpl';
        ##
        #  {*
        #  title: About
        #  published: true
        #  markdown: false
        #  *}
        #
        #  This is my website.
        ##
        $this->assertEqualsRegExp('This is my website.', static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEquals(array(
            'post' => array(
                'page' => true,
                'path' => 'about',
                'url' => 'http://website.com/about.html',
                'thumb' => '',
                'title' => 'About',
                'description' => '',
                'content' => 'This is my website.',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => true,
                'categories' => array(),
                'tags' => array(),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'About' => 'http://website.com/about.html',
            ),
        ), $template['vars']);
    }

    public function testCategorySimplePost()
    {
        $template = $this->blogPage('category/simple-post.html');
        $file = static::$folder.'category/simple-post/index.tpl';
        ##
        #  {*
        #  title: A Simple Post
        #  keywords: Simple, Markdown
        #  published: Aug 3, 2010
        #  author:  Joe Bloggs
        #  markdown: true
        #  *}
        #
        #  ### Header
        #
        #  Paragraph
        ##
        $this->assertEqualsRegExp(array(
            '<div itemscope itemtype="http://schema.org/Article">',
                '<div class="page-header"><h1 itemprop="name">A Simple Post</h1></div><br>',
                '<div itemprop="articleBody" style="padding-bottom:40px;">',
                    '<h3>Header</h3>',
                    '<p>Paragraph</p>',
                '</div>',
                '<p>Tagged:',
                    '&nbsp;<a href="http://website.com/blog/tags/simple.html" itemprop="keywords">Simple</a>',
                    '&nbsp;<a href="http://website.com/blog/tags/markdown.html" itemprop="keywords">Markdown</a>',
                '</p>',
                '<p>',
                    'Published:',
                    '<a href="http://website.com/blog/archives/2010/08/03.html" itemprop="datePublished">August  3, 2010</a>',
                    'by <a href="http://website.com/blog/authors/joe-bloggs.html" itemprop="author">Joe Bloggs</a>',
                '</p>',
            '</div>',
            '<ul class="pager">',
                '<li class="next"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post &raquo;</a></li>',
            '</ul>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEqualsRegExp('<h3>Header</h3><p>Paragraph</p>', $template['vars']['post']['content']);
        unset($template['vars']['post']['content']);
        $this->assertEquals(array(
            'post' => array(
                'page' => false,
                'path' => 'category/simple-post',
                'url' => 'http://website.com/category/simple-post.html',
                'thumb' => '',
                'title' => 'A Simple Post',
                'description' => '',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => strtotime('Aug 3, 2010'),
                'categories' => array(
                    array(
                        'name' => 'Category',
                        'path' => 'category',
                        'url' => 'http://website.com/category.html',
                        'thumb' => '',
                    ),
                ),
                'tags' => array(
                    array(
                        'name' => 'Simple',
                        'path' => 'simple',
                        'url' => 'http://website.com/blog/tags/simple.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'Markdown',
                        'path' => 'markdown',
                        'url' => 'http://website.com/blog/tags/markdown.html',
                        'thumb' => '',
                    ),
                ),
                'author' => array(
                    'name' => 'Joe Bloggs',
                    'path' => 'joe-bloggs',
                    'url' => 'http://website.com/blog/authors/joe-bloggs.html',
                    'thumb' => 'http://website.com/page/blog/user.jpg',
                ),
                'archive' => 'http://website.com/blog/archives/2010/08/03.html',
                'previous' => null,
                'next' => array(
                    'url' => 'http://website.com/category/subcategory/flowery-post.html',
                    'title' => 'A Flowery Post',
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'A Simple Post' => 'http://website.com/category/simple-post.html',
            ),
        ), $template['vars']);
    }

    public function testCategorySubcategoryFeaturedPost()
    {
        $template = $this->blogPage('category/subcategory/featured-post.html');
        $file = static::$folder.'category/subcategory/featured-post/index.tpl';
        ##
        #  {*
        #  title: A Featured Post
        #  keywords: Featured, markdown
        #  published: Sep 12, 2010
        #  author: jOe bLoGgS
        #  featured: true
        #  markdown: true
        #  *}
        #
        #  1. One
        #  2. Two
        #  3. Three
        ##
        $this->assertEqualsRegExp(array(
            '<div itemscope itemtype="http://schema.org/Article">',
                '<div class="page-header"><h1 itemprop="name">A Featured Post</h1></div><br>',
                '<div itemprop="articleBody" style="padding-bottom:40px;">',
                    '<ol>',
                        '<li>One</li>',
                        '<li>Two</li>',
                        '<li>Three</li>',
                    '</ol>',
                '</div>',
                '<p>Tagged:',
                    '&nbsp;<a href="http://website.com/blog/tags/markdown.html" itemprop="keywords">Markdown</a>',
                    '&nbsp;<a href="http://website.com/blog/tags/featured.html" itemprop="keywords">Featured</a>',
                '</p>',
                '<p>',
                    'Published:',
                    '<a href="http://website.com/blog/archives/2010/09/12.html" itemprop="datePublished">September 12, 2010</a>',
                    'by <a href="http://website.com/blog/authors/joe-bloggs.html" itemprop="author">Joe Bloggs</a>',
                '</p>',
            '</div>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEqualsRegExp('<ol><li>One</li><li>Two</li><li>Three</li></ol>', $template['vars']['post']['content']);
        unset($template['vars']['post']['content']);
        $this->assertEquals(array(
            'post' => array(
                'page' => false,
                'path' => 'category/subcategory/featured-post',
                'url' => 'http://website.com/category/subcategory/featured-post.html',
                'thumb' => '',
                'title' => 'A Featured Post',
                'description' => '',
                'updated' => filemtime($file),
                'featured' => true,
                'published' => strtotime('Sep 12, 2010'),
                'categories' => array(
                    array(
                        'name' => 'Category',
                        'path' => 'category',
                        'url' => 'http://website.com/category.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'Subcategory',
                        'path' => 'category/subcategory',
                        'url' => 'http://website.com/category/subcategory.html',
                        'thumb' => '',
                    ),
                ),
                'tags' => array(
                    array(
                        'name' => 'Markdown',
                        'path' => 'markdown',
                        'url' => 'http://website.com/blog/tags/markdown.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'Featured',
                        'path' => 'featured',
                        'url' => 'http://website.com/blog/tags/featured.html',
                        'thumb' => '',
                    ),
                ),
                'author' => array(
                    'name' => 'Joe Bloggs',
                    'path' => 'joe-bloggs',
                    'url' => 'http://website.com/blog/authors/joe-bloggs.html',
                    'thumb' => 'http://website.com/page/blog/user.jpg',
                ),
                'archive' => 'http://website.com/blog/archives/2010/09/12.html',
                'previous' => null,
                'next' => null,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Subcategory' => 'http://website.com/category/subcategory.html',
                'A Featured Post' => 'http://website.com/category/subcategory/featured-post.html',
            ),
        ), $template['vars']);
    }

    public function testCategorySubcategoryFloweryPost()
    {
        $template = $this->blogPage('category/subcategory/flowery-post.html');
        $file = static::$folder.'category/subcategory/flowery-post/index.tpl';
        ##
        #  {*
        #  Title: A Flowery Post
        #  Description: Aren't they beautiful?
        #  Keywords: Flowers, nature
        #  Published: Sep 12, 2010
        #  *}
        #
        #  {$page->title}
        #
        #  <img src="{'flowers.jpg'|link}">
        #
        #  Aren't they beautiful?
        ##
        $image = Page::html()->url('page', 'blog/content/category/subcategory/flowery-post/flowers.jpg');
        $this->assertEqualsRegExp(array(
            '<div itemscope itemtype="http://schema.org/Article">',
                '<div class="page-header"><h1 itemprop="name">A Flowery Post</h1></div><br>',
                '<div itemprop="articleBody" style="padding-bottom:40px;">',
                    'A Flowery Post',
                    '<img src="'.$image.'">',
                    "Aren't they beautiful?",
                '</div>',
                '<p>Tagged:',
                    '&nbsp;<a href="http://website.com/blog/tags/flowers.html" itemprop="keywords">Flowers</a>',
                    '&nbsp;<a href="http://website.com/blog/tags/nature.html" itemprop="keywords">nature</a>',
                '</p>',
                '<p>',
                    'Published:',
                    '<a href="http://website.com/blog/archives/2010/09/12.html" itemprop="datePublished">September 12, 2010</a>',
                '</p>',
            '</div>',
            '<ul class="pager">',
                '<li class="previous"><a href="http://website.com/category/simple-post.html">&laquo; A Simple Post</a></li>',
                '<li class="next"><a href="http://website.com/uncategorized-post.html">Uncategorized Post &raquo;</a></li>',
            '</ul>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $image = Page::html()->url('page', 'blog/content/category/subcategory/flowery-post/flowers.jpg');
        $this->assertEqualsRegExp(array(
            'A Flowery Post',
            '<img src="'.$image.'">',
            "Aren't they beautiful?",
        ), $template['vars']['post']['content']);
        unset($template['vars']['post']['content']);
        $this->assertEquals(array(
            'post' => array(
                'page' => false,
                'path' => 'category/subcategory/flowery-post',
                'url' => 'http://website.com/category/subcategory/flowery-post.html',
                'thumb' => '',
                'title' => 'A Flowery Post',
                'description' => 'Aren\'t they beautiful?',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => strtotime('Sep 12, 2010'),
                'categories' => array(
                    array(
                        'name' => 'Category',
                        'path' => 'category',
                        'url' => 'http://website.com/category.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'Subcategory',
                        'path' => 'category/subcategory',
                        'url' => 'http://website.com/category/subcategory.html',
                        'thumb' => '',
                    ),
                ),
                'tags' => array(
                    array(
                        'name' => 'Flowers',
                        'path' => 'flowers',
                        'url' => 'http://website.com/blog/tags/flowers.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'nature',
                        'path' => 'nature',
                        'url' => 'http://website.com/blog/tags/nature.html',
                        'thumb' => '',
                    ),
                ),
                'author' => array(),
                'archive' => 'http://website.com/blog/archives/2010/09/12.html',
                'previous' => array(
                    'url' => 'http://website.com/category/simple-post.html',
                    'title' => 'A Simple Post',
                ),
                'next' => array(
                    'url' => 'http://website.com/uncategorized-post.html',
                    'title' => 'Uncategorized Post',
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Subcategory' => 'http://website.com/category/subcategory.html',
                'A Flowery Post' => 'http://website.com/category/subcategory/flowery-post.html',
            ),
        ), $template['vars']);
        $template = $this->blogPage('category/subcategory/flowery-post.html?search=beauty');
        $this->assertEquals(array('beautiful'), $template['vars']['search']);
    }

    // http://wpcandy.s3.amazonaws.com/resources/postsxml.zip
    // https://wpcom-themes.svn.automattic.com/demo/theme-unit-test-data.xml
    public function testIndexPage()
    {
        $template = $this->blogPage('');
        $file = static::$folder.'index/index.tpl';
        ##
        #  {*
        #  title: Welcome to My Website
        #  keywords: simple, markDown
        #  published: true
        #  markdown: true
        #  *}
        #
        #  This is the index page.
        ##
        $this->assertEqualsRegExp('<p>This is the index page.</p>', static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEquals(array(
            'post' => array(
                'page' => true,
                'path' => 'index',
                'url' => 'http://website.com/',
                'thumb' => '',
                'title' => 'Welcome to My Website',
                'description' => '',
                'content' => '<p>This is the index page.</p>',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => true,
                'categories' => array(),
                'tags' => array(
                    array(
                        'name' => 'Simple',
                        'path' => 'simple',
                        'url' => 'http://website.com/blog/tags/simple.html',
                        'thumb' => '',
                    ),
                    array(
                        'name' => 'Markdown',
                        'path' => 'markdown',
                        'url' => 'http://website.com/blog/tags/markdown.html',
                        'thumb' => '',
                    ),
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Welcome to My Website' => 'http://website.com/',
            ),
        ), $template['vars']);
    }

    public function testUncategorizedPost()
    {
        $template = $this->blogPage('uncategorized-post.html');
        $file = static::$folder.'uncategorized-post/index.tpl';
        ##
        #  {*
        #  Title: Uncategorized Post
        #  Published: Oct 3, 2010
        #  *}
        #
        #  A post without a category
        ##
        $this->assertEqualsRegExp(array(
            '<div itemscope itemtype="http://schema.org/Article">',
                '<div class="page-header"><h1 itemprop="name">Uncategorized Post</h1></div><br>',
                '<div itemprop="articleBody" style="padding-bottom:40px;">',
                    'A post without a category',
                '</div>',
                '<p>',
                    'Published:',
                    '<a href="http://website.com/blog/archives/2010/10/03.html" itemprop="datePublished">October  3, 2010</a>',
                '</p>',
            '</div>',
            '<ul class="pager">',
                '<li class="previous"><a href="http://website.com/category/subcategory/flowery-post.html">&laquo; A Flowery Post</a></li>',
            '</ul>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEquals(array(
            'post' => array(
                'page' => false,
                'path' => 'uncategorized-post',
                'url' => 'http://website.com/uncategorized-post.html',
                'thumb' => '',
                'title' => 'Uncategorized Post',
                'description' => '',
                'content' => 'A post without a category',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => strtotime('Oct 3, 2010'),
                'categories' => array(),
                'tags' => array(),
                'author' => array(),
                'archive' => 'http://website.com/blog/archives/2010/10/03.html',
                'previous' => array(
                    'url' => 'http://website.com/category/subcategory/flowery-post.html',
                    'title' => 'A Flowery Post',
                ),
                'next' => null,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Uncategorized Post' => 'http://website.com/uncategorized-post.html',
            ),
        ), $template['vars']);
    }

    public function testBlogListings()
    {
        $template = $this->blogPage('blog.html');
        $this->assertEqualsRegExp(array(
            '<h2>Blog Posts</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/uncategorized-post.html">Uncategorized Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br><span itemprop="headline">Aren\'t they beautiful?</span>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'listings' => array(),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(4, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,6,4,2), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 6 - uncategorized (Oct 3 2010)
        // 4 - flowery (Sep 12 2008) category/subcategory
        // 2 - simple (Aug 3 2008) category
    }

    public function testBlogListingsSearch()
    {
        $page = Page::html();
        $search = '"simple post"';
        $template = $this->blogPage('blog.html', array('search' => $search));
        $this->assertEqualsRegExp(array(
            '<h2>Search Results for \'"simple post"\'</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
                '<br>A <b>Simple</b> <b>Post</b>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'listings' => array(
                'search' => $search,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Search' => $page->url('add', 'http://website.com/blog.html', 'search', $search),
            ),
            'search' => $search,
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        unset($template['vars']['listings']['count']); // to test the actual query
        $this->assertEquals(1, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(2), array_keys($listings));
        // 2 - simple (Aug 3 2008) category
        $this->assertEquals('A <b>Simple</b> <b>Post</b>', $listings[2]['snippet']);
        $this->assertEquals(array('simple post'), $listings[2]['words']);
    }

    public function testBlogCategoriesSearch()
    {
        $page = Page::html();
        $search = 'beauty';
        $template = $this->blogPage('category.html', array('search' => $search));
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/category.html">Category</a></li>',
                '<li class="active">Search</li>',
            '</ul>',
            '<h2>Search Results for \'beauty\'</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br>Aren\'t they <b>beautiful</b>?',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'listings' => array(
                'categories' => array(1, 2),
                'search' => $search,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Search' => $page->url('add', 'http://website.com/category.html', 'search', $search),
            ),
            'category' => array(
                'Category',
            ),
            'categories' => array(
                array(
                    'name' => 'Category',
                    'path' => 'category',
                    'url' => 'http://website.com/category.html',
                    'thumb' => '',
                    'count' => 3,
                    'subs' => array(
                        array(
                            'name' => 'Subcategory',
                            'path' => 'category/subcategory',
                            'url' => 'http://website.com/category/subcategory.html',
                            'thumb' => '',
                            'count' => 2,
                        ),
                    ),
                ),
            ),
            'search' => $search,
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        unset($template['vars']['listings']['count']); // to test the actual query
        $this->assertEquals(1, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(4), array_keys($listings));
        // 4 - flowery (Sep 12 2008) category/subcategory
        $this->assertEquals('Aren\'t they <b>beautiful</b>?', $listings[4]['snippet']);
        $this->assertEquals(array('beautiful'), $listings[4]['words']);
    }

    public function testSimilarQuery()
    {
        $template = $this->blogPage(''); // keywords: simple, markDown
        $posts = static::$blog->query('similar', 10); // determined via $page->keywords
        $this->assertEquals(array(2,3), array_keys($posts));
        // 2 - simple (Aug 3 2008) category - keywords: Simple, Markdown
        // 3 - featured (Sep 12 2008) category/subcategory - keywords: Featured, markdown
        
        // manual query
        $posts = static::$blog->query('similar', array(5, 'simple')); // specify keywords to use
        $this->assertEquals(array(2), array_keys($posts));
        $posts = static::$blog->query('similar', array(5 => 'simple')); // specify keywords to use
        $this->assertEquals(array(2), array_keys($posts));
        $posts = static::$blog->query('similar', array(5 => 'not-exists'));
        $this->assertEquals(array(), $posts); // no results
    }

    public function testPostsQuery()
    {
        $posts = static::$blog->query('posts', array(
            'uncategorized-post',
            'nonexistant-post',
            'category/subcategory/flowery-post',
            'category/subcategory/featured-post',
        ));
        $this->assertEquals(array(6,4,3), array_keys($posts));
        // 6 - uncategorized (Oct 3 2010)
        // 4 - flowery (Sep 12 2008) category/subcategory
        // 3 - featured (Sep 12 2008) category/subcategory
    }

    public function testCategoriesQuery()
    {
        $this->assertEquals(array(
            array(
                'name' => 'Category',
                'path' => 'category',
                'url' => 'http://website.com/category.html',
                'thumb' => '',
                'count' => 3,
                'subs' => array(
                    array(
                        'name' => 'Subcategory',
                        'path' => 'category/subcategory',
                        'url' => 'http://website.com/category/subcategory.html',
                        'thumb' => '',
                        'count' => 2,
                    ),
                ),
            ),
        ), static::$blog->query('categories', 5));
        $this->assertEquals(array(), static::$blog->query('categories', 0)); // limit 0
    }

    public function testBlogCategoryListings()
    {
        self::remove(static::$folder.'undefined');
        mkdir(static::$folder.'undefined', 0755, true); // to bypass preliminary folder check
        $this->assertFalse($this->blogPage('undefined.html'));
        
        $template = $this->blogPage('category.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li class="active">Category</li>',
            '</ul>',
            '<h2>Category Posts</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br><span itemprop="headline">Aren\'t they beautiful?</span>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'listings' => array(
                'categories' => array(1, 2),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
            ),
            'category' => array(
                'Category',
            ),
            'categories' => array(
                array(
                    'name' => 'Category',
                    'path' => 'category',
                    'url' => 'http://website.com/category.html',
                    'thumb' => '',
                    'count' => 3,
                    'subs' => array(
                        array(
                            'name' => 'Subcategory',
                            'path' => 'category/subcategory',
                            'url' => 'http://website.com/category/subcategory.html',
                            'thumb' => '',
                            'count' => 2,
                        ),
                    ),
                ),
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(3, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(3, static::$blog->query(array('categories' => 'category'), 'count')); // to test string conversion
        $this->assertEquals(array(3,4,2), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 4 - flowery (Sep 12 2008) category/subcategory
        // 2 - simple (Aug 3 2008) category
    }

    public function testBlogCategorySubcategoryListings()
    {
        $template = $this->blogPage('category/subcategory.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/category.html">Category</a></li>',
                '<li class="active">Subcategory</li>',
            '</ul>',
            '<h2>Category &raquo; Subcategory Posts</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br><span itemprop="headline">Aren\'t they beautiful?</span>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'listings' => array(
                'categories' => array(2),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Subcategory' => 'http://website.com/category/subcategory.html',
            ),
            'category' => array(
                'Category',
                'Subcategory',
            ),
            'categories' => array(
                array(
                    'name' => 'Subcategory',
                    'path' => 'category/subcategory',
                    'url' => 'http://website.com/category/subcategory.html',
                    'thumb' => '',
                    'count' => 2,
                ),
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(2, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,4), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 4 - flowery (Sep 12 2008) category/subcategory
    }

    public function testArchivesListings()
    {
        $template = $this->blogPage('blog/archives.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li class="active">Archives</li>',
            '</ul>',
            '<h2>The Archives</h2>',
            '<h3><a href="http://website.com/blog/archives/2010.html">2010</a> <span class="label label-primary">4</span></h3>',
            '<div class="row">',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/01.html" class="btn btn-link btn-block">Jan </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/02.html" class="btn btn-link btn-block">Feb </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/03.html" class="btn btn-link btn-block">Mar </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/04.html" class="btn btn-link btn-block">Apr </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/05.html" class="btn btn-link btn-block">May </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/06.html" class="btn btn-link btn-block">Jun </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/07.html" class="btn btn-link btn-block">Jul </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/08.html" class="btn btn-link btn-block">Aug  <br> <span class="label label-primary">1</span> </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/09.html" class="btn btn-link btn-block">Sep  <br> <span class="label label-primary">2</span> </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/10.html" class="btn btn-link btn-block">Oct  <br> <span class="label label-primary">1</span> </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/11.html" class="btn btn-link btn-block">Nov </a>',
                '</div>',
                '<div class="col-sm-1 text-center">',
                    '<a href="http://website.com/blog/archives/2010/12.html" class="btn btn-link btn-block">Dec </a>',
                '</div>',
            '</div>',
            '<br>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-archives.tpl', $template['file']);
        $this->assertEquals(array(
            'archives' => array(
                2010 => array(
                    'count' => 4,
                    'url' => 'http://website.com/blog/archives/2010.html',
                    'months' => array(
                        'Jan' => array(
                            'url' => 'http://website.com/blog/archives/2010/01.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,1,15,2010),
                        ),
                        'Feb' => array(
                            'url' => 'http://website.com/blog/archives/2010/02.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,2,15,2010),
                        ),
                        'Mar' => array(
                            'url' => 'http://website.com/blog/archives/2010/03.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,3,15,2010),
                        ),
                        'Apr' => array(
                            'url' => 'http://website.com/blog/archives/2010/04.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,4,15,2010),
                        ),
                        'May' => array(
                            'url' => 'http://website.com/blog/archives/2010/05.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,5,15,2010),
                        ),
                        'Jun' => array(
                            'url' => 'http://website.com/blog/archives/2010/06.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,6,15,2010),
                        ),
                        'Jul' => array(
                            'url' => 'http://website.com/blog/archives/2010/07.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,7,15,2010),
                        ),
                        'Aug' => array(
                            'url' => 'http://website.com/blog/archives/2010/08.html',
                            'count' => 1,
                            'time' => mktime(0,0,0,8,15,2010),
                        ),
                        'Sep' => array(
                            'url' => 'http://website.com/blog/archives/2010/09.html',
                            'count' => 2,
                            'time' => mktime(0,0,0,9,15,2010),
                        ),
                        'Oct' => array(
                            'url' => 'http://website.com/blog/archives/2010/10.html',
                            'count' => 1,
                            'time' => mktime(0,0,0,10,15,2010),
                        ),
                        'Nov' => array(
                            'url' => 'http://website.com/blog/archives/2010/11.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,11,15,2010),
                        ),
                        'Dec' => array(
                            'url' => 'http://website.com/blog/archives/2010/12.html',
                            'count' => 0,
                            'time' => mktime(0,0,0,12,15,2010),
                        ),
                    ),
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Archives' => 'http://website.com/blog/archives.html',
            ),
        ), $template['vars']);
    }

    public function testArchivesYearlyListings()
    {
        $template = $this->blogPage('blog/archives/2010.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/blog/archives.html">Archives</a></li>',
                '<li class="active">2010</li>',
            '</ul>',
            '<h2>2010 Archives</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/uncategorized-post.html">Uncategorized Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br><span itemprop="headline">Aren\'t they beautiful?</span>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'archive' => array(
                'date' => 1262304000,
                'year' => 2010,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Archives' => 'http://website.com/blog/archives.html',
                '2010' => 'http://website.com/blog/archives/2010.html',
            ),
            'listings' => array(
                'archives' => array(1262304000, 1293839999), // from, to
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(4, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,6,4,2), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 6 - uncategorized (Oct 3 2010)
        // 4 - flowery (Sep 12 2008) category/subcategory
        // 2 - simple (Aug 3 2008) category
    }

    public function testArchivesMonthlyListings()
    {
        $template = $this->blogPage('blog/archives/2010/09.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/blog/archives.html">Archives</a></li>',
                '<li><a href="http://website.com/blog/archives/2010.html">2010</a></li>',
                '<li class="active">September</li>',
            '</ul>',
            '<h2>September 2010 Archives</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/flowery-post.html">A Flowery Post</a></big>',
                '<br><span itemprop="headline">Aren\'t they beautiful?</span>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'archive' => array(
                'date' => mktime(0,0,0,9,1,2010),
                'year' => 2010,
                'month' => 'September',
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Archives' => 'http://website.com/blog/archives.html',
                '2010' => 'http://website.com/blog/archives/2010.html',
                'September' => 'http://website.com/blog/archives/2010/09.html',
            ),
            'listings' => array(
                'archives' => array(mktime(0,0,0,9,1,2010), mktime(23,59,59,10,0,2010)), // from, to
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(2, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,4), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 4 - flowery (Sep 12 2008) category/subcategory
    }

    public function testArchivesDailyListings()
    {
        $template = $this->blogPage('blog/archives/2010/10/03.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/blog/archives.html">Archives</a></li>',
                '<li><a href="http://website.com/blog/archives/2010.html">2010</a></li>',
                '<li><a href="http://website.com/blog/archives/2010/10.html">October</a></li>',
                '<li class="active">3</li>',
            '</ul>',
            '<h2>October 3, 2010 Archives</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/uncategorized-post.html">Uncategorized Post</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'archive' => array(
                'date' => mktime(0,0,0,10,3,2010),
                'year' => 2010,
                'month' => 'October',
                'day' => 3,
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Archives' => 'http://website.com/blog/archives.html',
                '2010' => 'http://website.com/blog/archives/2010.html',
                'October' => 'http://website.com/blog/archives/2010/10.html',
                '3' => 'http://website.com/blog/archives/2010/10/03.html',
            ),
            'listings' => array(
                'archives' => array(mktime(0,0,0,10,3,2010), mktime(23,59,59,10,3,2010)), // from, to
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        $this->assertEquals(1, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(6), array_keys($listings));
        // 6 - uncategorized (Oct 3 2010)
    }

    public function testAuthorsListings()
    {
        $template = $this->blogPage('blog/authors.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li class="active">Authors</li>',
            '</ul>',
            '<h2>Authors</h2>',
            '<p><a href="http://website.com/blog/authors/joe-bloggs.html">Joe Bloggs <span class="badge">2</span></a></p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-authors.tpl', $template['file']);
        $this->assertEquals(array(
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Authors' => 'http://website.com/blog/authors.html',
            ),
            'authors' => array(
                array(
                    'name' => 'Joe Bloggs',
                    'path' => 'joe-bloggs',
                    'url' => 'http://website.com/blog/authors/joe-bloggs.html',
                    'thumb' => 'http://website.com/page/blog/user.jpg',
                    'latest' => strtotime('Sep 12, 2010'),
                    'count' => 2,
                ),
            ),
        ), $template['vars']);
        
        // manual query
        $authors = static::$blog->query('authors', 5); // limit 5 authors
        $this->assertEquals(array(
            array(
                'name' => 'Joe Bloggs',
                'path' => 'joe-bloggs',
                'url' => 'http://website.com/blog/authors/joe-bloggs.html',
                'thumb' => 'http://website.com/page/blog/user.jpg',
                'latest' => strtotime('Sep 12, 2010'),
                'count' => 2,
            ),
        ), $authors);
    }

    public function testAuthorsIndividualListings()
    {
        $this->assertFalse($this->blogPage('blog/authors/kyle-gadd.html'));
        
        $template = $this->blogPage('blog/authors/joe-bloggs.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/blog/authors.html">Authors</a></li>',
                '<li class="active">Joe Bloggs</li>',
            '</ul>',
            '<h2>Author: Joe Bloggs</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Authors' => 'http://website.com/blog/authors.html',
                'Joe Bloggs' => 'http://website.com/blog/authors/joe-bloggs.html',
            ),
            'author' => array(
                'name' => 'Joe Bloggs',
                'path' => 'joe-bloggs',
                'url' => 'http://website.com/blog/authors/joe-bloggs.html',
                'thumb' => 'http://website.com/page/blog/user.jpg',
                'latest' => strtotime('Sep 12, 2010'),
                'count' => 2,
            ),
            'listings' => array(
                'count' => 2,
                'authors' => 'joe-bloggs',
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        unset($template['vars']['listings']['count']); // to test the actual query
        $this->assertEquals(2, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,2), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 2 - simple (Aug 3 2008) category
    }

    public function testTagsListings()
    {
        $template = $this->blogPage('blog/tags.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li class="active">Tags</li>',
            '</ul>',
            '<h2>Tag Cloud</h2>',
            '<p>',
                '<a class="text-primary" style="font-size:15px; padding:0px 5px;" href="http://website.com/blog/tags/featured.html">Featured</a>',
                '<a class="text-primary" style="font-size:15px; padding:0px 5px;" href="http://website.com/blog/tags/flowers.html">Flowers</a>',
                '<a class="text-danger" style="font-size:27px; padding:0px 5px;" href="http://website.com/blog/tags/markdown.html">Markdown</a>',
                '<a class="text-primary" style="font-size:15px; padding:0px 5px;" href="http://website.com/blog/tags/nature.html">nature</a>',
                '<a class="text-success" style="font-size:21px; padding:0px 5px;" href="http://website.com/blog/tags/simple.html">Simple</a>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-tags.tpl', $template['file']);
        $this->assertEquals(array(
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Tags' => 'http://website.com/blog/tags.html',
            ),
            'tags' => array(
                array(
                    'name' => 'Featured',
                    'path' => 'featured',
                    'url' => 'http://website.com/blog/tags/featured.html',
                    'thumb' => '',
                    'latest' => strtotime('Sep 12, 2010'),
                    'count' => 1,
                    'rank' => 1,
                ),
                array(
                    'name' => 'Flowers',
                    'path' => 'flowers',
                    'url' => 'http://website.com/blog/tags/flowers.html',
                    'thumb' => '',
                    'latest' => strtotime('Sep 12, 2010'),
                    'count' => 1,
                    'rank' => 1,
                ),
                array(
                    'name' => 'Markdown',
                    'path' => 'markdown',
                    'url' => 'http://website.com/blog/tags/markdown.html',
                    'thumb' => '',
                    'latest' => strtotime('Sep 12, 2010'),
                    'count' => 3,
                    'rank' => 5,
                ),
                array(
                    'name' => 'nature',
                    'path' => 'nature',
                    'url' => 'http://website.com/blog/tags/nature.html',
                    'thumb' => '',
                    'latest' => strtotime('Sep 12, 2010'),
                    'count' => 1,
                    'rank' => 1,
                ),
                array(
                    'name' => 'Simple',
                    'path' => 'simple',
                    'url' => 'http://website.com/blog/tags/simple.html',
                    'thumb' => '',
                    'latest' => strtotime('Aug 3, 2010'),
                    'count' => 2,
                    'rank' => 3,
                ),
            ),
        ), $template['vars']);
        
        
        // manual query
        $this->assertEquals(array(
            array(
                'name' => 'Markdown',
                'path' => 'markdown',
                'url' => 'http://website.com/blog/tags/markdown.html',
                'thumb' => '',
                'latest' => strtotime('Sep 12, 2010'),
                'count' => 3,
                'rank' => 5,
            ),
            array(
                'name' => 'Simple',
                'path' => 'simple',
                'url' => 'http://website.com/blog/tags/simple.html',
                'thumb' => '',
                'latest' => strtotime('Aug 3, 2010'),
                'count' => 2,
                'rank' => 1,
            ),
        ), static::$blog->query('tags', 2)); // limit 2 tags
    }

    public function testTagsIndividualListings()
    {
        $this->assertFalse($this->blogPage('blog/tags/undefined.html'));
        
        $template = $this->blogPage('blog/tags/markdown.html');
        $this->assertEqualsRegExp(array(
            '<ul class="breadcrumb">',
                '<li><a href="http://website.com/blog.html">Blog</a></li>',
                '<li><a href="http://website.com/blog/tags.html">Tags</a></li>',
                '<li class="active">Markdown</li>',
            '</ul>',
            '<h2>Tag: Markdown</h2>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/subcategory/featured-post.html">A Featured Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/category/simple-post.html">A Simple Post</a></big>',
            '</p>',
            '<p itemscope itemtype="http://schema.org/Article">',
                '<big itemprop="name"><a href="http://website.com/">Welcome to My Website</a></big>',
            '</p>',
        ), static::$blog->theme->fetchSmarty($template));
        $this->assertEquals('blog-listings.tpl', $template['file']);
        $this->assertEquals(array(
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Tags' => 'http://website.com/blog/tags.html',
                'Markdown' => 'http://website.com/blog/tags/markdown.html',
            ),
            'tag' => array(
                'name' => 'Markdown',
                'path' => 'markdown',
                'url' => 'http://website.com/blog/tags/markdown.html',
                'thumb' => '',
                'latest' => strtotime('Sep 12, 2010'),
                'count' => 3,
            ),
            'listings' => array(
                'count' => 3,
                'tags' => 'markdown',
            ),
        ), $template['vars']);
        $pagination = new Pagination();
        $listings = static::$blog->query($template['vars']['listings'], $pagination);
        unset($template['vars']['listings']['count']); // to test the actual query
        $this->assertEquals(3, static::$blog->query($template['vars']['listings'], 'count'));
        $this->assertEquals(array(3,2,5), array_keys($listings));
        // 3 - featured (Sep 12 2008) category/subcategory
        // 2 - simple (Aug 3 2008) category
        // 5 - index
    }

    public function testFeedListings()
    {
        $template = $this->blogPage('blog/feed.xml');
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Response', $template);
        $this->assertEquals('application/rss+xml', $template->headers->get('Content-Type'));
        $this->assertEqualsRegExp(array(
            '<?xml version="1.0"?>',
            '<rss version="2.0">',
            '<channel>',
                '<title>{.*}</title>',
                '<link>http://website.com/blog.html</link>',
                '<description></description>',
                    '<item>',
                        '<title>A Featured Post</title>',
                        '<link>http://website.com/category/subcategory/featured-post.html</link>',
                        '<description><![CDATA[',
                            '<ol>',
                                '<li>One</li>',
                                '<li>Two</li>',
                                '<li>Three</li>',
                            '</ol>',
                        ']]></description>',
                        '<pubDate>'.date(\DATE_RFC2822, strtotime('Sep 12, 2010')).'</pubDate>',
                        '<guid isPermaLink="true">http://website.com/category/subcategory/featured-post.html</guid>',
                    '</item>',
                    '<item>',
                        '<title>Uncategorized Post</title>',
                        '<link>http://website.com/uncategorized-post.html</link>',
                        '<description><![CDATA[',
                            'A post without a category',
                        ']]></description>',
                        '<pubDate>'.date(\DATE_RFC2822, strtotime('Oct 3, 2010')).'</pubDate>',
                        '<guid isPermaLink="true">http://website.com/uncategorized-post.html</guid>',
                    '</item>',
                    '<item>',
                        '<title>A Flowery Post</title>',
                        '<link>http://website.com/category/subcategory/flowery-post.html</link>',
                        '<description><![CDATA[',
                            'A Flowery Post',
                            '<img src="http://website.com/page/blog/content/category/subcategory/flowery-post/flowers.jpg">',
                            'Aren\'t they beautiful?',
                        ']]></description>',
                        '<pubDate>'.date(\DATE_RFC2822, strtotime('Sep 12, 2010')).'</pubDate>',
                        '<guid isPermaLink="true">http://website.com/category/subcategory/flowery-post.html</guid>',
                    '</item>',
                    '<item>',
                        '<title>A Simple Post</title>',
                        '<link>http://website.com/category/simple-post.html</link>',
                        '<description><![CDATA[',
                            '<h3>Header</h3>',
                            '<p>Paragraph</p>',
                        ']]></description>',
                        '<pubDate>'.date(\DATE_RFC2822, strtotime('Aug 3, 2010')).'</pubDate>',
                        '<guid isPermaLink="true">http://website.com/category/simple-post.html</guid>',
                    '</item>',
                '</channel>',
            '</rss>',
        ), $template->getContent());
    }

    public function testNewPageInsertUpdateDelete()
    {
        $file = str_replace('/', DIRECTORY_SEPARATOR, static::$folder.'category/unpublished-post/index.tpl');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        self::remove($file);
        file_put_contents($file, implode("\n", array(
            '{*',
            'title: Unpublished Post',
            'keywords: Unpublished',
            'published: true', // will add to sitemap
            'author: anonymous',
            '*}',
            '',
            'Hello {if} Smarty',
        )));
        $template = $this->blogPage('category/unpublished-post.html');
        $sitemap = new Sitemap();
        $this->assertEquals(1, $sitemap->db->value('SELECT COUNT(*) FROM sitemap WHERE path = ?', 'category/unpublished-post'));
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEquals(array(
            'post' => array(
                'page' => true,
                'path' => 'category/unpublished-post',
                'url' => 'http://website.com/category/unpublished-post.html',
                'thumb' => '',
                'title' => 'Unpublished Post',
                'description' => '',
                'content' => '<p>Syntax error in template "file:blog/content/category/unpublished-post/index.tpl"  on line 8 "Hello {if} Smarty" missing if condition</p>',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => true,
                'categories' => array(
                    array(
                        'name' => 'Category',
                        'path' => 'category',
                        'url' => 'http://website.com/category.html',
                        'thumb' => '',
                    ),
                ),
                'tags' => array(
                    array(
                        'name' => 'Unpublished',
                        'path' => 'unpublished',
                        'url' => 'http://website.com/blog/tags/unpublished.html',
                        'thumb' => '',
                    ),
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Unpublished Post' => 'http://website.com/category/unpublished-post.html',
            ),
        ), $template['vars']);
        file_put_contents($file, implode("\n", array(
            '{*',
            'title: Unpublished Post',
            'keywords: Unpublished',
            'published: false', // will remove from sitemap
            '*}',
            '',
            'Hello Smarty Pants',
        )));
        $template = $this->blogPage('category/unpublished-post.html');
        $this->assertEquals(0, $sitemap->db->value('SELECT COUNT(*) FROM sitemap WHERE path = ?', 'category/unpublished-post'));
        unset($sitemap);
        $this->assertEquals('blog-post.tpl', $template['file']);
        $this->assertEquals(array(
            'post' => array(
                'page' => true,
                'path' => 'category/unpublished-post',
                'url' => 'http://website.com/category/unpublished-post.html',
                'thumb' => '',
                'title' => 'Unpublished Post',
                'description' => '',
                'content' => 'Hello Smarty Pants',
                'updated' => filemtime($file),
                'featured' => false,
                'published' => false,
                'categories' => array(
                    array(
                        'name' => 'Category',
                        'path' => 'category',
                        'url' => 'http://website.com/category.html',
                        'thumb' => '',
                    ),
                ),
                'tags' => array(
                    array(
                        'name' => 'Unpublished',
                        'path' => 'unpublished',
                        'url' => 'http://website.com/blog/tags/unpublished.html',
                        'thumb' => '',
                    ),
                ),
            ),
            'breadcrumbs' => array(
                'Blog' => 'http://website.com/blog.html',
                'Category' => 'http://website.com/category.html',
                'Unpublished Post' => 'http://website.com/category/unpublished-post.html',
            ),
        ), $template['vars']);
        
        
        $template = $this->blogPage('category/unpublished-post.html'); // is not updated
        
        // verify seo folders enforced on a single access
        rename(static::$folder.'category/unpublished-post', static::$folder.'category/Unpublished--post');
        $this->assertFileExists(static::$folder.'category/Unpublished--post');
        $this->assertFalse(static::$blog->file('category/Unpublished--post'));
        $this->assertFileNotExists(static::$folder.'category/Unpublished--post');
        $this->assertFileExists(static::$folder.'category/unpublished-post');
        
        self::remove(dirname($file));
        $this->assertFalse($this->blogPage('category/unpublished-post.html')); // an orphaned directory
    }

    public function testUpdatedConfigFile()
    {
        $this->assertFileExists(static::$config);
        $this->assertEquals(implode("\n", array(
            'blog:',
            "    name: 'Another { BootPress } Site'",
            "    thumb: ''",
            "    summary: ''",
            '    listings: blog',
            '    breadcrumb: Blog',
            'themes:',
            '    default: default',
            '    bootstrap: 3.3.7',
            'authors:',
            '    joe-bloggs:',
            "        name: 'Joe Bloggs'",
            '        thumb: user.jpg',
            '    anonymous:',
            '        name: anonymous',
            'categories:',
            '    category:',
            '        name: Category',
            '    category/subcategory:',
            '        name: Subcategory',
            '    unknown:',
            '        name: UnKnown',
            'tags:',
            '    featured:',
            '        name: Featured',
            '    flowers:',
            '        name: Flowers',
            '    markdown:',
            '        name: Markdown',
            '    nature:',
            '        name: nature',
            '    simple:',
            '        name: Simple',
            '    unpublished:',
            '        name: Unpublished',
            '    not-exists:',
            "        name: 'What are you doing?'",
        )), trim(file_get_contents(static::$config)));
        $this->assertEquals(array(
            'blog' => array(
                'name' => 'Another { BootPress } Site',
                'thumb' => '',
                'summary' => '',
                'listings' => 'blog',
                'breadcrumb' => 'Blog',
            ),
            'themes' => array(
                'default' => 'default',
                'bootstrap' => '3.3.7',
            ),
            'authors' => array(
                'joe-bloggs' => array(
                    'name' => 'Joe Bloggs',
                    'thumb' => 'user.jpg',
                ),
                'anonymous' => array(
                    'name' => 'anonymous',
                ),
            ),
            'categories' => array(
                'category' => array(
                    'name' => 'Category',
                ),
                'category/subcategory' => array(
                    'name' => 'Subcategory',
                ),
                'unknown' => array(
                    'name' => 'UnKnown',
                ),
            ),
            'tags' => array(
                'featured' => array(
                    'name' => 'Featured',
                ),
                'flowers' => array(
                    'name' => 'Flowers',
                ),
                'markdown' => array(
                    'name' => 'Markdown',
                ),
                'nature' => array(
                    'name' => 'nature',
                ),
                'simple' => array(
                    'name' => 'Simple',
                ),
                'unpublished' => array(
                    'name' => 'Unpublished',
                ),
                'not-exists' => array(
                    'name' => 'What are you doing?',
                ),
            ),
        ), static::$blog->config());
        $this->assertEquals(array(
            'name' => 'Another { BootPress } Site',
            'thumb' => '',
            'summary' => '',
            'listings' => 'blog',
            'breadcrumb' => 'Blog',
        ), static::$blog->config('blog'));
        $this->assertEquals('blog', static::$blog->config('blog', 'listings'));
        $this->assertEquals('Joe Bloggs', static::$blog->config('authors', 'joe-bloggs', 'name'));
        $this->assertNull(static::$blog->config('authors', 'anonymous', 'thumb'));
    }
    
    public function testThemeGlobalVarsMethod()
    {
        $this->blogPage('theme.html');
        static::$blog->theme->globalVars('foo', array('bar'));
        static::$blog->theme->globalVars(array(
            'foo' => array('baz', 'qux'),
            'hodge' => 'podge',
        ));
        $this->assertAttributeEquals(array(
            'foo' => array('bar', 'baz', 'qux'),
            'hodge' => 'podge',
            'blog' => array(
                'name' => 'Another { BootPress } Site',
                'thumb' => '',
                'summary' => '',
                'listings' => 'blog',
                'breadcrumb' => 'Blog',
            ),
        ), 'vars', static::$blog->theme);
        static::$blog->theme->globalVars('foo', 'bar');
        $this->assertAttributeEquals(array(
            'foo' => 'bar',
            'hodge' => 'podge',
            'blog' => array(
                'name' => 'Another { BootPress } Site',
                'thumb' => '',
                'summary' => '',
                'listings' => 'blog',
                'breadcrumb' => 'Blog',
            ),
        ), 'vars', static::$blog->theme);
    }
    
    public function testThemeAddPageMethodMethod()
    {
        static::$blog->theme->addPageMethod('hello', function(){return 'World';});
        $this->setExpectedException('\LogicException');
        static::$blog->theme->addPageMethod('amigo', 'Hello');
    }
    
    public function testThemeFetchSmartyBlogFoldersException()
    {
        $this->setExpectedException('\LogicException');
        static::$blog->theme->fetchSmarty('');
    }
    
    public function testThemeFetchSmartyMissingFileException()
    {
        $this->setExpectedException('\LogicException');
        static::$blog->theme->fetchSmarty(static::$folder.'missing.tpl');
    }
    
    public function testThemeFetchSmartyDefaultFile()
    {
        $page = Page::html();
        $default = $page->file('default.tpl');
        file_put_contents($default, 'Default {template}');
        
        // Syntax Error
        $this->assertEquals('<p>Syntax error in template "file:blog/themes/default/default.tpl"  on line 1 "Default {template}" unknown tag "template"</p>', static::$blog->theme->fetchSmarty(array(
            'default' => $page->dir(),
            'vars' => array('syntax'=>'error'),
            'file' => 'default.tpl',
        )));
        
        // Testing Mode
        unlink($default);
        $default = $page->file('blog/themes/default/default.tpl');
        $this->assertFileExists($default);
        $this->assertEquals('Syntax error in template "file:blog/themes/default/default.tpl"  on line 1 "Default {template}" unknown tag "template"', static::$blog->theme->fetchSmarty($default, array('syntax'=>'error'), 'testing'));
        unlink($default);
        
        // Theme::link($path) array
        $path = array(
            'image.jpg?query=string' => '.jpg image',
            'png image.png' => 'path/image.png',
        );
        $this->assertEquals(array(
            'http://website.com/page/blog/themes/default/image.jpg?query=string' => '.jpg image',
            'png image.png' => 'http://website.com/page/blog/themes/default/path/image.png',
        ), static::$blog->theme->link($path));
    }
    
    public function testThemeLayoutMethod()
    {
        $page = Page::html();
        $layout = $page->dir('blog/themes/default');
        $this->assertFileExists($layout); // should be created automatically
        $this->assertEquals('<p>Content</p>', static::$blog->theme->layout('<p>Content</p>'));
        file_put_contents($layout.'index.tpl', implode("\n", array(
            '{$page->amigo} {$page->hello()} War {$bp->version}',
            '{$bp->icon("thumbs-up")} {$content}',
            '{$page->filter("content", "invalid", "error")}',
            '{$page->filter("content", "prepend", "SPECIAL MESSAGE")}',
        )));
        $this->assertEqualsRegExp(array(
            'World War 3.3.7',
            '<span class="glyphicon glyphicon-thumbs-up"></span>',
            '<p>Content</p>',
        ), static::$blog->theme->layout('<p>Content</p>'));
        
        // test default theme selection
        mkdir($layout.'child', 0755, true);
        $page->theme = 'default/child';
        file_put_contents($layout.'config.yml', 'default: theme'."\n".'name: Parent');
        file_put_contents($layout.'child/config.yml', 'name: Child');
        file_put_contents($layout.'child/index.tpl', '{$config.name} {$bp->framework} {$config.default}');
        $this->assertEquals('Child bootstrap theme', static::$blog->theme->layout(''));
        // $this->assertEquals('http://website.com/page/temp/theme/asset.css', $page->url('theme', 'asset.css'));
        
        // No Theme
        $page->theme = false;
        $this->assertEquals('HTML', static::$blog->theme->layout('HTML'));
        
        // Callable Theme
        $page->theme = function($content, $bp, $vars) {
            return 'Callable '.$content;
        };
        $this->assertEquals('Callable HTML', static::$blog->theme->layout('HTML'));
        
        // File Theme
        $page->theme = $layout.'theme.php';
        file_put_contents($page->theme, implode("\n", array(
            '<?php',
            'extract($params);',
            'echo "File {$content}";',
        )));
        $this->assertEquals('File HTML', static::$blog->theme->layout('HTML'));
    }
    
    protected function blogPage($path, array $query = array())
    {
        $request = Request::create('http://website.com/'.$path, 'GET', $query);
        Page::html(array(
            'testing' => true,
            'dir' => __DIR__.'/page',
            'url' => 'http://website.com/',
            'suffix' => '.html',
        ), $request, 'overthrow');
        static::$blog = new Blog('blog');

        return static::$blog->page();
    }
    
    private static function remove($target)
    {
        if (is_dir($target)) {
            $files = glob(rtrim($target, '/\\').'/*');
            foreach ($files as $file) {
                self::remove($file);
            }
            @rmdir($target);
        } elseif (is_file($target)) {
            @unlink($target);
        }
    }
}
