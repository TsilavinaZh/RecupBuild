// The pages of RecupBuild (src/Views), rendered by the server: this file only adds what
// needs a script. Every page still works without it (forms post, links lead, the
// home page shows the plan, the result shows its still 3D picture).
'use strict'

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

// ----------------------------------------------------------------- menu (phones)
document.querySelector('[data-menu]')?.addEventListener('click', (event) => {
  const button = event.currentTarget
  const open = button.getAttribute('aria-expanded') !== 'true'
  button.setAttribute('aria-expanded', String(open))
  document.body.classList.toggle('menu-open', open)
})

// -------------------------------------------------------- confirmation dialogs
// A button with data-confirm="id" opens <dialog id="id">: deleting and signing out
// are asked twice. Escape, "Cancel" or a click outside close it.
document.addEventListener('click', (event) => {
  const opener = event.target.closest('[data-confirm]')
  if (opener) {
    const dialog = document.getElementById(opener.dataset.confirm)
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.showModal()
      dialog.querySelector('[data-close]')?.focus()
    }
    return
  }
  if (event.target.closest('[data-close]')) {
    event.target.closest('dialog')?.close()
    return
  }
  if (event.target instanceof HTMLDialogElement) event.target.close()
  if (event.target.closest('[data-print]')) window.print()
})

document.addEventListener(
  'close',
  (event) => {
    if (event.target instanceof HTMLDialogElement) document.querySelector(`[data-confirm="${event.target.id}"]`)?.focus()
  },
  true,
)

// ------------------------------------------------------------- waiting screen
// A form (or its submit button) with data-loading="label" data-seconds="70" asks the AI:
// the browser waits for the next page, and this screen says how long it has waited.
const waiting = document.getElementById('waiting')
let timer = null

function wait(label, seconds) {
  if (!waiting) return
  const bar = waiting.querySelector('.waiting-bar span')
  const time = waiting.querySelector('.waiting-time')
  waiting.querySelector('.waiting-label').textContent = label
  waiting.hidden = false
  document.body.classList.add('is-waiting')
  const start = Date.now()
  const tick = () => {
    const elapsed = (Date.now() - start) / 1000
    // Fast at first, slower near the end; it never reaches the end on its own.
    const percent = Math.min(97, Math.round(100 * (1 - Math.exp((-1.6 * elapsed) / seconds))))
    bar.style.transform = `scaleX(${percent / 100})`
    const overdue = elapsed > seconds * 1.2
    time.textContent = `${waiting.dataset.elapsed.replace('{seconds}', Math.floor(elapsed))} · ${
      overdue ? waiting.dataset.overdue : waiting.dataset.expected.replace('{seconds}', seconds)
    }`
  }
  tick()
  clearInterval(timer)
  timer = setInterval(tick, 250)
}

document.addEventListener('submit', (event) => {
  const source = event.submitter?.dataset.loading ? event.submitter : event.target.closest('[data-loading]')
  if (!source?.dataset.loading) return
  wait(source.dataset.loading, Number(source.dataset.seconds) || 30)
})

// Back from the history (bfcache): no waiting screen left over.
window.addEventListener('pageshow', () => {
  if (!waiting) return
  waiting.hidden = true
  document.body.classList.remove('is-waiting')
  clearInterval(timer)
})

// ----------------------------------------------------------- photo dropzones
// The chosen photo shows in the zone before it is sent; a file dropped on the zone is
// put in its input (so the form posts it as if it had been chosen).
for (const zone of document.querySelectorAll('[data-drop]')) {
  const input = zone.querySelector('input[type=file]')
  const preview = document.getElementById(input?.dataset.preview ?? '')
  const show = () => {
    const file = input.files?.[0]
    if (!file || !preview) return
    preview.src = URL.createObjectURL(file)
    preview.hidden = false
    zone.classList.add('has-photo')
  }
  input?.addEventListener('change', show)
  zone.addEventListener('dragover', (event) => {
    event.preventDefault()
    zone.classList.add('over')
  })
  zone.addEventListener('dragleave', () => zone.classList.remove('over'))
  zone.addEventListener('drop', (event) => {
    event.preventDefault()
    zone.classList.remove('over')
    if (event.dataTransfer?.files?.length && input) {
      input.files = event.dataTransfer.files
      show()
    }
  })
}

// ------------------------------------------------------- the home page scene
// The heap, measured, planned, then assembled: four moments that play on their own
// while the scene is on screen. A button picks one and stops the loop.
const scene = document.querySelector('[data-scene]')
if (scene) {
  const moments = ['pile', 'measure', 'plan', 'result']
  const duration = { pile: 2600, measure: 2800, plan: 3600, result: 4200 }
  const pieces = [...scene.querySelectorAll('[data-piece]')].map((element, index) => ({
    element,
    at: JSON.parse(element.dataset.piece),
    index,
  }))
  let moment = 'plan'
  let auto = !reduceMotion
  let visible = false
  let loop = null

  const show = (next) => {
    moment = next
    scene.dataset.moment = next
    for (const { element, at, index } of pieces) {
      const [x, y, r, sx, sy, o] = at[next]
      element.removeAttribute('transform')
      element.style.transitionDelay = `${index * 55}ms`
      element.style.transform = `translate(${x}px, ${y}px) rotate(${r}deg) scale(${sx}, ${sy})`
      if (!element.classList.contains('a4')) element.style.opacity = String(o)
    }
    for (const button of scene.querySelectorAll('[data-moment-button]')) {
      button.setAttribute('aria-pressed', String(button.dataset.momentButton === next))
    }
  }
  const schedule = () => {
    clearTimeout(loop)
    if (!auto || !visible) return
    loop = setTimeout(() => {
      show(moments[(moments.indexOf(moment) + 1) % moments.length])
      schedule()
    }, duration[moment])
  }
  scene.addEventListener('click', (event) => {
    const button = event.target.closest('[data-moment-button]')
    if (!button) return
    auto = false
    clearTimeout(loop)
    show(button.dataset.momentButton)
  })
  show(reduceMotion ? 'plan' : 'pile')
  new IntersectionObserver(([entry]) => {
    visible = entry?.isIntersecting ?? false
    schedule()
  }, { threshold: 0.25 }).observe(scene)
}

// --------------------------------------------------------- reveal on scroll
// Elements with data-reveal get data-revealed when they come into view (the CSS
// animates only for visitors who accept motion).
if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        entry.target.dataset.revealed = ''
        observer.unobserve(entry.target)
      }
    },
    { rootMargin: '0px 0px -8% 0px', threshold: 0.08 },
  )
  for (const element of document.querySelectorAll('[data-reveal]')) observer.observe(element)
  document.documentElement.classList.add('reveals')
}

// Fill the measuring-tape meters of the profile (set from data-fill: the page allows no inline style).
for (const fill of document.querySelectorAll('.tape-fill[data-fill]')) {
  fill.style.setProperty('--fill', fill.dataset.fill)
}
