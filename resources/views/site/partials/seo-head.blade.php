@php
    $seoSiteName = trim((string) ($siteName ?? $siteTitle ?? config('geoflow.site_name', config('app.name'))));
    $seoTitle = trim((string) ($pageTitle ?? $seoSiteName));
    $seoDescription = trim((string) ($pageDescription ?? ($siteDescription ?? '')));
    $seoKeywords = trim((string) ($pageKeywords ?? ($siteKeywords ?? '')));
    $seoCanonical = trim((string) ($canonicalUrl ?? url()->current()));
    $seoOgType = trim((string) ($pageOgType ?? 'website'));
    $seoImage = trim((string) ($pageImage ?? ''));
    $pwaCurrentSite = app(\App\Support\Site\CurrentSite::class);
    $pwaEnabled = $pwaCurrentSite->isResolved() && $pwaCurrentSite->isPrimary();

    if ($seoTitle === '') {
        $seoTitle = $seoSiteName;
    }

    if ($seoOgType === '') {
        $seoOgType = 'website';
    }
@endphp
@if($pwaEnabled)
    <x-pwa-head />
    @vite('resources/js/pwa.js')
@endif
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
@if(isset($siteIndexingAllowed) && !$siteIndexingAllowed)
    <meta name="robots" content="noindex, nofollow">
@endif
@if($seoKeywords !== '')
    <meta name="keywords" content="{{ $seoKeywords }}">
@endif
@if(!empty($siteFavicon))
    <link rel="icon" href="{{ $siteFavicon }}">
@endif
@if($seoCanonical !== '')
    <link rel="canonical" href="{{ $seoCanonical }}">
@endif
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:type" content="{{ $seoOgType }}">
@if($seoCanonical !== '')
    <meta property="og:url" content="{{ $seoCanonical }}">
@endif
@if($seoSiteName !== '')
    <meta property="og:site_name" content="{{ $seoSiteName }}">
@endif
@if($seoImage !== '')
    @php
        $seoImageUrl = preg_match('/^(?:https?:)?\\/\\//i', $seoImage) === 1 ? $seoImage : url($seoImage);
    @endphp
    <meta property="og:image" content="{{ $seoImageUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ $seoImageUrl }}">
@else
    <meta name="twitter:card" content="summary">
@endif
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
