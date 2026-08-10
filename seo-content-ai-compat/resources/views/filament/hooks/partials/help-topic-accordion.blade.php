<div class="help-main" data-help-main>
    <div class="help-breadcrumb" data-help-breadcrumb>
        <span x-text="$store.help.activeGroup?.title || ''"></span>
        <template x-if="$store.help.mobileView === 'content' && $store.help.activeTopic">
            <span> / <span x-text="$store.help.activeTopic.title"></span></span>
        </template>
    </div>

    <div class="help-topic-list" data-help-topic-list>
        <template x-for="topic in $store.help.activeTopics" :key="topic.id">
            <section
                class="help-topic"
                data-help-topic
                x-bind:data-help-topic-id="topic.id"
                x-bind:class="{ 'is-open': $store.help.activeTopicId === topic.id }"
            >
                <h3 class="help-topic__title">
                    <button
                        type="button"
                        class="help-topic-trigger"
                        data-help-topic-trigger
                        x-bind:aria-expanded="$store.help.activeTopicId === topic.id ? 'true' : 'false'"
                        x-on:click="$store.help.toggleTopic(topic.id)"
                    >
                        <span x-text="topic.title"></span>
                        <span class="help-topic-trigger__chevron" aria-hidden="true"></span>
                    </button>
                </h3>

                <div
                    class="help-topic-content"
                    data-help-topic-content
                    x-show="$store.help.activeTopicId === topic.id"
                    x-cloak
                >
                    <p
                        class="help-topic-content__summary"
                        x-show="topic.summary"
                        x-text="topic.summary"
                    ></p>
                    <ol class="help-topic-content__steps" x-show="Array.isArray(topic.steps) && topic.steps.length">
                        <template x-for="step in (topic.steps || [])" :key="step">
                            <li x-text="step"></li>
                        </template>
                    </ol>
                    <p
                        class="help-topic-content__summary"
                        x-show="topic.content"
                        x-text="topic.content"
                    ></p>
                    <button
                        type="button"
                        class="help-topic-content__goto"
                        data-help-topic-goto
                        x-show="topic.target"
                        x-on:click="$store.help.goToTarget(topic.target)"
                    >
                        {{ __('seo-content-ai::filament.help.goto') }}
                    </button>
                </div>
            </section>
        </template>

        <p class="help-empty" x-show="$store.help.activeTopics.length === 0 && $store.help.filteredGroups.length > 0">
            {{ __('seo-content-ai::filament.help.no_topics') }}
        </p>
    </div>
</div>
