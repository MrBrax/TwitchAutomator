<template>
    <div class="container">
        <section class="section">
            <div class="section-title"><h1>Full VOD fetch and burn chat</h1></div>
            <div class="section-content">
                <tools-burn-form />
            </div>
        </section>

        <section class="section">
            <div class="section-title"><h1>VOD download</h1></div>
            <div class="section-content">
                <tools-vod-download-form />
            </div>
        </section>

        <section class="section">
            <div class="section-title"><h1>Chat download</h1></div>
            <div class="section-content">
                <tools-chat-download-form />
            </div>
        </section>

        <!--
        <section class="section">
            <div class="section-title"><h1>Saved VODs</h1></div>
            <div class="section-content">
                
                {% if saved_vods %}
                    <ul>
                    {% for vod in saved_vods %}
                        <li><a href="{{ base_path() }}/saved_vods/{{ vod.name }}">{{ vod.name }}</a> ({{ formatBytes(vod.size) }})</li>
                    {% endfor %}
                    </ul>
                {% else %}
                    <em>None</em>
                {% endif %}

            </div>
        </section>
        -->

        <section class="section">
            <div class="section-title"><h1>Current jobs</h1></div>
            <div class="section-content">
                <table>
                    <tr v-for="job in jobsData" :key="job.name">
                        <td>
                            <span class="text-overflow">{{ job.name }}</span>
                        </td>
                        <td>{{ job.pid }}</td>
                        <td><!-- {{ job.status }}-->{{ job.status ? "Running" : "Unexpected exit" }}</td>
                        <td>
                            <a class="button is-danger is-small" v-if="job.status" @click="killJob(job.name)" title="Kill job">
                                <span class="icon"><fa icon="trash"></fa></span>
                            </a>
                        </td>
                    </tr>
                </table>

                <em v-if="jobsData.length == 0">None</em>
            </div>
        </section>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import ToolsBurnForm from "@/components/forms/ToolsBurnForm.vue";
import ToolsVodDownloadForm from "@/components/forms/ToolsVodDownloadForm.vue";
import ToolsChatDownloadForm from "@/components/forms/ToolsChatDownloadForm.vue";

import type { ApiJob } from "@/twitchautomator.d";

export default defineComponent({
    name: "Tools",
    title: "Tools",
    data() {
        return {
            jobsData: [] as ApiJob[],
        };
    },
    created() {
        this.fetchData();
    },
    methods: {
        fetchData() {
            // this.settingsData = [];
            // this.settingsFields = [] as any;
            this.$http
                .get(`api/v0/jobs/list`)
                .then((response) => {
                    const json = response.data;
                    this.jobsData = json.data;
                })
                .catch((err) => {
                    console.error("about error", err.response);
                });
        },
        killJob(name: string) {
            if (!confirm(`Kill job "${name}?"`)) return;

            this.$http
                .post(`/api/v0/jobs/kill/${name}`)
                .then((response) => {
                    const json = response.data;
                    if (json.message) alert(json.message);
                    console.log(json);
                })
                .catch((err) => {
                    console.error("tools jobs fetch error", err.response);
                });

            /*
            fetch(`/api/v0/jobs/kill/${name}`, {
                // method: 'POST',
            })
            .then((response) => response.json())
            .then((json) => {
                setTimeout(() => {
                    this.fetchData();
                }, 2000);
            }).catch((test) => {
                console.error("Error", test);
            });
            */
        },
    },
    components: {
        ToolsBurnForm,
        ToolsVodDownloadForm,
        ToolsChatDownloadForm,
    },
});
</script>
