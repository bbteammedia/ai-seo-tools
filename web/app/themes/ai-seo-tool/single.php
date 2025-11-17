<?php

get_header();
?>

<main id="primary" class="min-h-screen bg-slate-50 py-12 lg:py-20">
    <div class="container mx-auto max-w-5xl px-6 lg:px-10">
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <?php
                $keywordsRaw = get_post_meta(get_the_ID(), '_bbseo_ai_keywords', true);
                $keywords = [];
                if (is_string($keywordsRaw) && $keywordsRaw !== '') {
                    $decoded = json_decode($keywordsRaw, true);
                    if (is_array($decoded)) {
                        $keywords = array_filter(array_map('sanitize_text_field', $decoded));
                    }
                }

                $outlineRaw = get_post_meta(get_the_ID(), '_bbseo_ai_outline', true);
                $outline = [];
                if (is_string($outlineRaw) && $outlineRaw !== '') {
                    $decodedOutline = json_decode($outlineRaw, true);
                    if (is_array($decodedOutline)) {
                        $outline = $decodedOutline;
                    }
                }
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('rounded-3xl bg-white shadow-xl ring-1 ring-slate-100'); ?>>
                    <header class="space-y-6 border-b border-slate-100 px-6 py-8 sm:px-8">
                        <div class="flex flex-wrap items-center text-sm text-slate-500 gap-3">
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-600">
                                <?php echo esc_html(get_the_date()); ?>
                            </span>
                            <span class="text-slate-300">•</span>
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <circle cx="12" cy="7" r="4" />
                                    <path d="M5.5 21a6.5 6.5 0 0 1 13 0" />
                                </svg>
                                <?php echo esc_html(get_the_author()); ?>
                            </span>
                        </div>
                        <h1 class="text-3xl font-semibold leading-tight text-slate-900 sm:text-4xl lg:text-5xl">
                            <?php the_title(); ?>
                        </h1>
                        <?php if (has_post_thumbnail()) : ?>
                            <figure class="overflow-hidden rounded-2xl bg-slate-100">
                                <?php the_post_thumbnail('large', [
                                    'class' => 'h-full w-full object-cover transition duration-700 ease-out hover:scale-[1.015]',
                                    'loading' => 'lazy',
                                ]); ?>
                                <?php if (get_the_post_thumbnail_caption()) : ?>
                                    <figcaption class="px-6 py-3 text-sm text-slate-500">
                                        <?php echo esc_html(get_the_post_thumbnail_caption()); ?>
                                    </figcaption>
                                <?php endif; ?>
                            </figure>
                        <?php endif; ?>
                    </header>

                    <div class="bbseo-article__content prose prose-slate mx-auto max-w-3xl px-6 py-10 sm:px-8 lg:prose-lg">
                        <?php the_content(); ?>
                    </div>

                    <?php if ($outline) : ?>
                        <section class="border-t border-slate-100 bg-slate-50/60 px-6 py-8 sm:px-8" style="display: none;">
                            <div class="mx-auto max-w-3xl">
                                <h2 class="text-lg font-semibold text-slate-900 mb-4"><?php esc_html_e('Article Highlights', 'ai-seo-tool'); ?></h2>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <?php foreach ($outline as $section) : ?>
                                        <div class="rounded-2xl border border-slate-100 bg-white/70 p-4 shadow-sm">
                                            <h3 class="text-base font-semibold text-slate-900">
                                                <?php echo esc_html($section['heading'] ?? ($section['id'] ?? '')); ?>
                                            </h3>
                                            <?php if (!empty($section['summary'])) : ?>
                                                <p class="mt-2 text-sm text-slate-600"><?php echo esc_html($section['summary']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <footer class="border-t border-slate-100 px-6 py-8 sm:px-8">
                        <div class="flex flex-col gap-6 lg:flex-row lg:justify-between">
                            <?php if ($keywords) : ?>
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500"><?php esc_html_e('Key Topics', 'ai-seo-tool'); ?></h3>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <?php foreach ($keywords as $keyword) : ?>
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">
                                                <?php echo esc_html($keyword); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="space-y-3">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500"><?php esc_html_e('Categories', 'ai-seo-tool'); ?></h3>
                                    <div class="mt-2 text-sm text-slate-700">
                                        <?php the_category(', '); ?>
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500"><?php esc_html_e('Tags', 'ai-seo-tool'); ?></h3>
                                    <div class="mt-2 text-sm text-slate-700">
                                        <?php the_tags('', ', '); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </footer>
                </article>

                <nav class="mt-10 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-100 bg-white/70 p-6 shadow-sm hover:border-slate-200 transition duration-200">
                        <p class="text-xs uppercase tracking-wide text-slate-500"><?php esc_html_e('Previous', 'ai-seo-tool'); ?></p>
                        <div class="mt-3 text-base font-semibold text-slate-900">
                            <?php previous_post_link('%link', esc_html__('← Previous article', 'ai-seo-tool')); ?>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white/70 p-6 text-right shadow-sm hover:border-slate-200 transition duration-200">
                        <p class="text-xs uppercase tracking-wide text-slate-500"><?php esc_html_e('Next', 'ai-seo-tool'); ?></p>
                        <div class="mt-3 text-base font-semibold text-slate-900">
                            <?php next_post_link('%link', esc_html__('Next article →', 'ai-seo-tool')); ?>
                        </div>
                    </div>
                </nav>
            <?php endwhile; ?>
        <?php else : ?>
            <p class="text-center text-slate-600"><?php esc_html_e('No posts found.', 'ai-seo-tool'); ?></p>
        <?php endif; ?>
    </div>
</main>

<?php
get_footer();
