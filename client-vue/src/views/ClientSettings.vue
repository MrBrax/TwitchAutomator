<template>
    <div class="container">
        <section class="section">
            <div class="section-title"><h1>Client settings</h1></div>
            <div class="section-content">
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.enableNotifications" /> Notifications</label>
                </div>
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.useSpeech" /> Use speech</label>
                </div>
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.singlePage" /> Single page</label>
                </div>
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.animationsEnabled" /> Enable animations</label>
                </div>
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.tooltipStatic" /> Static side menu tooltip</label>
                </div>
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.useRelativeTime" /> Use relative time</label>
                </div>
                <!--
                <div class="control">
                    <label class="checkbox"><input type="checkbox" v-model="updateConfig.showAdvancedInfo" /> Show advanced info</label>
                </div>
                -->
                <br />
                <div class="control">
                    <button class="button is-small" @click="requestNotifications">Request notification permissions</button>
                </div>
            </div>
        </section>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

const defaultConfig = {
    useSpeech: false,
    singlePage: false,
    enableNotifications: false,
    animationsEnabled: true,
    tooltipStatic: false,
    useRelativeTime: false,
    showAdvancedInfo: false,
};

export default defineComponent({
    title: "Client settings",
    data() {
        return {
            currentConfig: Object.assign({}, defaultConfig),
            updateConfig: Object.assign({}, defaultConfig),
        };
    },
    created() {
        const crConf = Object.assign({}, defaultConfig);

        const currentConfig = localStorage.getItem("twitchautomator_config") ? JSON.parse(localStorage.getItem("twitchautomator_config") as string) : crConf;

        this.updateConfig = currentConfig;
        this.currentConfig = currentConfig;
    },
    methods: {
        saveClientConfig() {
            localStorage.setItem("twitchautomator_config", JSON.stringify(this.updateConfig));
            if (this.currentConfig.enableNotifications !== this.updateConfig.enableNotifications && this.updateConfig.enableNotifications) {
                this.requestNotifications();
            }
            this.$store.commit("updateClientConfig", this.updateConfig);
        },
        requestNotifications() {
            if (!("Notification" in window)) {
                alert("This browser does not support desktop notification");
            } else if (Notification.permission === "granted") {
                // const notification = new Notification("Notifications already granted.");
            } else if (Notification.permission !== "denied") {
                Notification.requestPermission().then(function (permission) {
                    if (permission === "granted") {
                        new Notification("Notifications granted.");
                    }
                });
            }
        },
    },
    watch: {
        updateConfig: {
            handler() {
                this.saveClientConfig();
            },
            deep: true,
        },
    },
});
</script>
