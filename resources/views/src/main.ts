import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles.css'

// FontAwesome — registro global para que todos los componentes puedan usar
// <FontAwesomeIcon> sin importarlo individualmente.
import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { faFacebook, faInstagram, faWhatsapp } from '@fortawesome/free-brands-svg-icons'
import { fas } from '@fortawesome/free-solid-svg-icons'
library.add(faFacebook, faInstagram, faWhatsapp, fas)

const app = createApp(App)

app.component('FontAwesomeIcon', FontAwesomeIcon)
app.use(router)
app.mount('#app')
