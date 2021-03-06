<template>
    <div class="container vertical">
        <section class="section" data-section="vods">
            <div class="section-title"><h1>Recorded VODs</h1></div>
            <div class="section-content" v-if="$store.state.config && $store.state.streamerList && $store.state.streamerList.length > 0">
                <template v-if="!$store.state.clientConfig.singlePage">
                    <streamer v-for="streamer in sortedStreamers" v-bind:key="streamer.userid" v-bind:streamer="streamer" />
                </template>
                <template v-else>
                    <streamer v-bind:streamer="singleStreamer" />
                </template>
                <hr />
                <div class="dashboard-stats">
                    <strong>Total size: {{ formatBytes(totalSize) }}</strong>
                    <br />
                    <strong>Free space: {{ formatBytes(freeSize) }}</strong>
                </div>
            </div>
            <div class="section-content" v-else>
                <span class="icon"><fa icon="sync" spin></fa></span> Loading...
            </div>
        </section>

        <section class="section">
            <div class="section-title" @click="logToggle"><h1>Logs</h1></div>
            <div class="section-content" v-if="logVisible">
                <!--
                <select id="log_select">
                    {% for f in log_files %}
                        <option>{{ f }}</button>
                    {% endfor %}
                </select>
                -->

                <div class="log_viewer" ref="logViewer">
                    <table>
                        <tr v-for="line in logFiltered" :key="line" :class="'log-line log-line-' + line.level.toLowerCase()">
                            <td>{{ line.date_string }}</td>
                            <td>
                                <a @click="logSetFilter(line.module)">{{ line.module }}</a>
                            </td>
                            <td>{{ line.level }}</td>
                            <td>{{ line.text }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div id="js-status" :class="{ disconnected: ws && !wsConnected }" ref="js-status" @click="timer = 0">
        <template v-if="ws">
            {{ wsConnected ? "Connected" : wsConnecting ? "Connecting..." : "Disconnected" }}
        </template>
        <template v-else>
            {{ loading ? "Loading..." : `Refreshing in ${timer} seconds.` }}
        </template>
    </div>
    <div id="jobs-status" v-if="$store.state.jobList !== undefined">
        <table>
            <tr v-for="job in $store.state.jobList" :key="job.name">
                <td>
                    <span class="icon">
                        <fa icon="sync" spin v-if="job.status"></fa>
                        <fa icon="exclamation-triangle" v-else></fa>
                    </span>
                    <span class="text-overflow px-1">{{ job.name }}</span>
                </td>
                <td>{{ job.pid }}</td>
                <td><!-- {{ job.status }}-->{{ job.status ? "Running" : "Unexpected exit" }}</td>
            </tr>
        </table>

        <em v-if="$store.state.jobList.length == 0">None</em>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import Streamer from "@/components/Streamer.vue";
import type { ApiLogLine, ApiStreamer } from "@/twitchautomator.d";
import { format } from "date-fns";
import { MutationPayload } from "vuex";

export default defineComponent({
    name: "Dashboard",
    title(): string {
        if (this.streamersOnline > 0) return `[${this.streamersOnline}] Dashboard`;
        return "Dashboard";
    },
    data() {
        return {
            loading: false,
            timer: 120,
            timerMax: 120,
            interval: 0,
            totalSize: 0,
            freeSize: 0,
            logFilename: "",
            logLines: [] as ApiLogLine[],
            logFromLine: 0,
            logVisible: false,
            logModule: "",
            oldData: {} as Record<string, ApiStreamer>,
            notificationSub: Function as any,
            ws: {} as WebSocket,
            wsConnected: false,
            wsConnecting: false,
            wsKeepalive: 0,
            wsLastPing: 0,
            wsKeepaliveTime: 20000,
        };
    },
    created() {
        this.loading = true;
        this.fetchStreamers()
            .then((sl) => {
                this.$store.commit("updateStreamerList", sl);
                this.loading = false;
            })
            .then(() => {
                this.fetchLog();
            })
            .then(() => {
                this.fetchJobs();
            });
    },
    mounted() {
        this.processNotifications();

        if (this.$store.state.config.websocket_enabled) {
            this.connectWebsocket();
        } else {
            console.debug("No websocket url");
            this.interval = setInterval(() => {
                this.fetchTicker();
            }, 1000);
        }
    },
    unmounted() {
        if (this.interval) {
            clearTimeout(this.interval);
        }

        // unsub
        if (this.notificationSub) {
            console.log("unsubscribing from notifications, unmounted");
            this.notificationSub();
        }

        if (this.ws) {
            this.disconnectWebsocket();
        }
    },
    methods: {
        connectWebsocket() {
            if (this.ws) this.disconnectWebsocket();
            const proto = window.location.protocol === "https:" ? "wss://" : "ws://";
            const websocket_url_public = proto + window.location.host + this.$store.state.config.basepath + "/socket/";
            const websocket_url = process.env.NODE_ENV === "development" ? "ws://localhost:8765/socket/" : websocket_url_public;
            console.log(`Connecting to ${websocket_url}`);
            this.wsConnecting = true;
            this.ws = new WebSocket(websocket_url);
            this.ws.onopen = (ev: Event) => {
                console.log(`Connected to websocket!`);
                this.ws.send(JSON.stringify({ action: "helloworld" }));
                this.wsConnected = true;
                this.wsConnecting = false;
                this.wsKeepalive = setInterval(() => {
                    // console.debug("send ping");
                    this.ws.send("ping");
                }, this.wsKeepaliveTime);
            };
            this.ws.onmessage = (ev: MessageEvent) => {
                // console.log("ws message", ev);
                let text = ev.data;

                if (text == "pong") {
                    // console.log("pong recieved");
                    this.wsLastPing = Date.now();
                    return;
                }

                let json: any = {};
                try {
                    json = JSON.parse(text);
                } catch (error) {
                    console.error("Couldn't parse json", text);
                    return;
                }

                const action = json.data.action;

                if (action) {
                    const downloader_actions = [
                        "start_download",
                        "end_download",
                        "start_capture",
                        "end_capture",
                        "start_convert",
                        "end_convert",
                        "chapter_update",
                    ];
                    const job_actions = ["job_save", "job_clear"];
                    if (downloader_actions.indexOf(action) !== -1) {
                        console.log("Websocket update");
                        const vod = json.data.vod;
                       /*
                       if (vod) {
                            console.log("Websocket update vod", vod);
                            this.$store.commit("updateVod", vod);
                        }
                        */
                        this.fetchStreamers().then((sl) => {
                            this.$store.commit("updateStreamerList", sl);
                            this.loading = false;
                        });
                    } else if (job_actions.indexOf(action) !== -1) {
                        console.log(`Websocket jobs update: ${action}`, json.data.job_name, json.data.job);
                        this.fetchJobs();
                    } else if (action == "notify") {
                        // alert(json.data.text);
                        const toast = new Notification(json.data.text);
                        console.log(`Notify: ${json.data.text}`);
                    } else {
                        console.log(`Websocket wrong action (${action})`);
                    }
                } else {
                    console.log(`Websocket unknown data`, json.data);
                }
            };
            this.ws.onerror = (ev: Event) => {
                console.error(`Websocket error!`, ev);
                this.wsConnected = false;
                this.wsConnecting = false;
                clearInterval(this.wsKeepalive);
            };
            this.ws.onclose = (ev: CloseEvent) => {
                console.log(`Disconnected from websocket! (${ev.code}/${ev.reason})`);
                this.wsConnecting = false;
                setTimeout(() => {
                    if (!ev.wasClean) {
                        this.connectWebsocket();
                    }
                }, 10000);
                this.wsConnected = false;
                clearInterval(this.wsKeepalive);
            };
            return this.ws;
        },
        disconnectWebsocket() {
            if (this.ws && this.ws.close) {
                console.log("Closing websocket...");
                this.wsConnecting = false;
                this.ws.close(undefined, "pageleave");
                if (this.wsKeepalive) clearInterval(this.wsKeepalive);
            }
        },
        async fetchStreamers() {
            let response;
            try {
                response = await this.$http.get(`/api/v0/channels/list`);
            } catch (error) {
                console.error(error);
                return;
            }

            if (!response.data.data) {
                console.error("fetchStreamers invalid data", response.data);
                return;
            }

            this.totalSize = response.data.data.total_size;
            this.freeSize = response.data.data.free_size;

            return response.data.data.streamer_list;
        },
        async fetchJobs() {
            let response;

            try {
                response = await this.$http.get(`/api/v0/jobs/list`);
            } catch (error) {
                console.error(error);
                return;
            }

            const json = response.data;
            console.debug("Update jobs list", json.data);
            this.$store.commit("updateJobList", json.data);
        },
        async fetchLog() {
            // today's log file
            if (this.logFilename == "") {
                this.logFilename = format(new Date(), "yyyy-MM-dd");
            }

            let response;
            try {
                response = await this.$http.get(`/api/v0/log/${this.logFilename}/${this.logFromLine}`);
            } catch (error) {
                console.error(error);
                return;
            }

            // console.debug("log data", response.data);

            if (!response.data.data) {
                console.error("fetchLog invalid data", response.data);
                return;
            }

            if (!response.data.data.lines) return;

            this.logFromLine = response.data.data.last_line;

            this.logLines = this.logLines.concat(response.data.data.lines);

            // scroll to bottom
            setTimeout(() => {
                const lv = this.$refs.logViewer as HTMLDivElement;
                if (!lv) return;
                lv.scrollTop = lv.scrollHeight;
            }, 100);
        },
        async fetchTicker() {
            if (this.timer <= 0 && !this.loading) {
                this.loading = true;
                const streamerResult: ApiStreamer[] = await this.fetchStreamers();

                const isAnyoneLive = streamerResult.find((el) => el.is_live == true) !== undefined;

                if (!isAnyoneLive) {
                    if (this.timerMax < 1800 /* 30 minutes */) {
                        this.timerMax += 10;
                    }
                } else {
                    this.timerMax = 120;
                }

                this.$store.commit("updateStreamerList", streamerResult);

                this.fetchLog();

                this.fetchJobs();

                this.loading = false;

                this.timer = this.timerMax;
            } else {
                this.timer -= 1;
            }
        },
        processNotifications() {
            if (!this.$store.state.clientConfig.enableNotifications) {
                return;
            }

            console.log("Notifications enabled");

            this.notificationSub = this.$store.subscribe((mutation: MutationPayload) => {
                // unsub if changed
                if (!this.$store.state.clientConfig.enableNotifications) {
                    console.log("Notification setting disabled, stopping subscription.");
                    this.notificationSub();
                    return;
                }

                if (!mutation.payload) {
                    console.error("No payload for notification sub");
                    return;
                }

                if (mutation.type !== "updateStreamerList") {
                    console.error(`Streamer list notification check payload was ${mutation.type}, abort.`);
                    return;
                }

                // console.log("subscribe", mutation.payload, this.$store.state.streamerList);
                /*
                if( mutation.payload[0].current_game !== state.streamerList[0].current_game ){
                    alert( mutation.payload[0].display_name + ": " + mutation.payload[0].current_game );
                }*/
                // console.log( "values", Object.(mutation.payload[0]));
                const streamerPronounciation: { [key: string]: string } = {
                    pokelawls: "pookelawls",
                    xQcOW: "eckscueseeow",
                };

                // console.debug("notification payload", mutation);

                for (const streamer of mutation.payload as ApiStreamer[]) {
                    const username = streamer.display_name;

                    if (this.oldData && this.oldData[streamer.display_name]) {
                        const oldStreamer = this.oldData[streamer.display_name];

                        const opt = {
                            icon: streamer.channel_data.profile_image_url,
                            image: streamer.channel_data.profile_image_url,
                            body: streamer.current_game ? streamer.current_game.game_name : "No game",
                        };

                        let text = "";

                        if (!oldStreamer.is_live && streamer.is_live) {
                            text = `${username} is live!`;
                        }

                        if (streamer.is_live) {
                            // console.log("notification compare games", streamer.display_name, oldStreamer.current_game, streamer.current_game );

                            if (
                                (!oldStreamer.current_game && streamer.current_game) || // from no game to new game
                                (oldStreamer.current_game && streamer.current_game && oldStreamer.current_game.game_name !== streamer.current_game.game_name) // from old game to new game
                            ) {
                                // alert( streamer.display_name + " is now playing " + streamer.current_game.game_name );

                                if (streamer.current_game.favourite) {
                                    text = `${username} is now playing one of your favourite games: ${streamer.current_game.game_name}!`;
                                } else {
                                    text = `${username} is now playing ${streamer.current_game.game_name}!`;
                                }
                            }
                        }

                        if (oldStreamer.is_live && !streamer.is_live) {
                            text = `${username} has gone offline!`;
                        }

                        if (text !== "") {
                            console.log(`Notify: ${text}`);

                            if (Notification.permission === "granted") {
                                const toast = new Notification(text, opt);
                            }

                            const useSpeech = true;
                            if (useSpeech) {
                                let speakText = text;

                                if (streamerPronounciation[username]) {
                                    console.debug(`Using pronounciation for ${username}`);
                                    speakText = speakText.replace(username, streamerPronounciation[username]);
                                }
                                const utterance = new SpeechSynthesisUtterance(speakText);
                                window.speechSynthesis.speak(utterance);
                            }
                        } else {
                            // console.debug(`No notification text for ${streamer.display_name}`);
                        }
                    }

                    this.oldData[streamer.display_name] = streamer;
                }
            });
        },
        logSetFilter(val: string) {
            if (this.logModule) {
                this.logModule = "";
            } else {
                this.logModule = val;
            }
            console.log(`Log filter set to ${this.logModule}`);
        },
        logToggle() {
            this.logVisible = !this.logVisible;
            setTimeout(() => {
                const lv = this.$refs.logViewer as HTMLDivElement;
                if (!lv) return;
                lv.scrollTop = lv.scrollHeight;
            }, 100);
        },
    },
    computed: {
        sortedStreamers() {
            const streamers: ApiStreamer[] = this.$store.state.streamerList;
            return streamers.sort((a, b) => a.display_name.localeCompare(b.display_name));
        },
        logFiltered(): ApiLogLine[] {
            if (!this.logModule) return this.logLines;
            return this.logLines.filter((val) => val.module == this.logModule);
        },
        streamersOnline(): number {
            if (!this.$store.state.streamerList) return 0;
            return this.$store.state.streamerList.filter((a) => a.is_live).length;
        },
        singleStreamer(): ApiStreamer | undefined {
            if (!this.$store.state.streamerList) return undefined;

            const current = this.$route.query.channel as string;
            if (current !== undefined) {
                return this.$store.state.streamerList.find((u) => u.display_name === current);
            } else {
                // this.$route.query.channel = this.$store.state.streamerList[0].display_name;
                return this.$store.state.streamerList[0];
            }
        },
    },
    components: {
        Streamer,
        // HelloWorld
    },
    watch: {
        streamersOnline() {
            document.title = this.streamersOnline > 0 ? `[${this.streamersOnline}] Dashboard - TwitchAutomator` : `Dashboard - TwitchAutomator`;
        },
    },
});
</script>
