<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\ArticleStickyAdPicker;
use App\Support\Site\ArticleTextAdPicker;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\SiteThemeViewResolver;
use App\Support\GeoFlow\KeywordNormalizer;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 前台文章详情（对齐旧版 article.php：浏览计数、Markdown 正文、相关文章）。
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly SiteUrlGenerator $urls,
    ) {}

    public function show(string $slug): View
    {
        $article = $this->siteArticles->query()
            ->where('slug', $slug)
            ->with(['category', 'author'])
            ->first();

        if (! $article instanceof Article) {
            throw new NotFoundHttpException(__('site.article_not_found'));
        }

        if (! app(SiteThemePreviewContext::class)->isActive()) {
            $article->increment('view_count');
            $article->refresh();
        }

        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $rawContent = (string) $article->content;
        $body = ArticleHtmlPresenter::stripLeadingTitleHeading($rawContent, (string) $article->title);
        $excerpt = trim((string) $article->excerpt);
        if ($excerpt !== '') {
            $excerpt = ArticleHtmlPresenter::stripLeadingTitleHeading($excerpt, (string) $article->title);
        }

        $contentHtml = ArticleTextAdPicker::injectIntoContentHtml(
            ArticleHtmlPresenter::markdownToHtml($body, (string) $article->title)
        );
        $excerptPlain = $excerpt !== '' ? ArticleHtmlPresenter::cardSummary($article, 160) : '';

        $tags = $this->keywordTags((string) $article->keywords);
        $focusKeyword = trim((string) $article->original_keyword);
        if ($focusKeyword !== '' && ! in_array($focusKeyword, $tags, true)) {
            array_unshift($tags, $focusKeyword);
        }

        $related = $this->siteArticles->query()
            ->where('category_id', $article->category_id)
            ->whereKeyNot($article->id)
            ->inRandomOrder()
            ->limit(6)
            ->get(['id', 'title', 'slug']);

        $pageTitle = (string) $article->title;
        $storedMetaDescription = trim((string) $article->meta_description);
        $pageDescription = $storedMetaDescription !== ''
            ? mb_substr($storedMetaDescription, 0, 160)
            : ($excerptPlain !== '' ? $excerptPlain : ArticleHtmlPresenter::cardSummary($article, 160));
        $pageKeywords = implode(',', $tags);
        $pageImage = $this->firstImageUrl($contentHtml);

        $stickyAd = ArticleStickyAdPicker::firstEnabled();

        return SiteThemeViewResolver::first('article', [
            'activeNav' => 'article',
            'article' => $article,
            'contentHtml' => $contentHtml,
            'excerptPlain' => $excerptPlain,
            'tags' => $tags,
            'relatedArticles' => $related,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageDescription,
            'pageKeywords' => $pageKeywords,
            'pageImage' => $pageImage,
            'pageOgType' => 'article',
            'stickyAd' => $stickyAd,
            'canonicalUrl' => $this->urls->article($article),
        ]);
    }

    /**
     * @return list<string>
     */
    private function keywordTags(string $keywords): array
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            return [];
        }

        return array_slice(KeywordNormalizer::split($keywords), 0, 12);
    }

    private function firstImageUrl(string $contentHtml): ?string
    {
        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/iu', $contentHtml, $matches) !== 1) {
            return null;
        }

        $url = trim((string) ($matches[2] ?? ''));

        return $url !== '' ? $url : null;
    }
}
