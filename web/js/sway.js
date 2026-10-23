// Nav bar bg based on scroll position
const nav = document.querySelector('nav')
const getScrollThreshold = () => window.innerWidth / 5

function updateNav() {
  nav.classList.toggle('scrolled', window.scrollY > getScrollThreshold())
}

window.addEventListener('scroll', updateNav)
updateNav()

// Mobile nav toggle
const menuBtn = document.getElementById('menu-btn')
const mainNav = document.querySelector('.main-nav')

menuBtn.addEventListener('click', () => {
  mainNav.classList.toggle('active')
})

// Event carousel
const container = document.querySelector('.event-container')

if (container) {
  const blocks = Array.from(container.querySelectorAll('.event-block'))

  // only clone for the loop illusion when there's more than one real slide to loop through
  const slides = blocks.length > 1 ? [...blocks, blocks[0].cloneNode(true)] : blocks
  if (slides.length > blocks.length) container.appendChild(slides[slides.length - 1])
  let index = 0

  const tick = () => {
      // wrap with modulo so a throttled background tab can't push index past the last slide
      index = (index + 1) % slides.length
      container.scrollTo({ left: slides[index].offsetLeft, behavior: 'smooth' })

      if (index === slides.length - 1) {
          const snapBack = () => {
              container.scrollTo({ left: blocks[0].offsetLeft, behavior: 'auto' })
              index = 0
          }

          // wait for the smooth scroll onto the clone to finish before snapping back unseen
          if ('onscrollend' in window) {
              container.addEventListener('scrollend', snapBack, { once: true })
          } else {
              setTimeout(snapBack, 600)
          }
      }
  }

  // nothing to carousel with 0 or 1 real event banners
  if (slides.length > 1) {
      let intervalId = setInterval(tick, 5000)

      container.addEventListener('mouseenter', () => clearInterval(intervalId))
      container.addEventListener('mouseleave', () => { intervalId = setInterval(tick, 5000) })
  }
}


// Event carousel v2

const EASINGS = {
    linear: (t) => t,
    easeOutQuad: (t) => 1 - (1 - t) ** 2,
    easeInOutQuad: (t) => (t < 0.5 ? 2 * t ** 2 : 1 - (-2 * t + 2) ** 2 / 2),
    easeOutCubic: (t) => 1 - (1 - t) ** 3,
    easeInOutCubic: (t) => (t < 0.5 ? 4 * t ** 3 : 1 - (-2 * t + 2) ** 3 / 2),
    easeOutExpo: (t) => (t === 1 ? 1 : 1 - 2 ** (-10 * t)),
    easeOutBack: (t) => 1 + 2.70158 * (t - 1) ** 3 + 1.70158 * (t - 1) ** 2,
}
const SCROLL_EASING = EASINGS.easeInOutCubic
const SCROLL_DURATION = 500
const SCROLL_INTERVAL = 5000

const containerV2 = document.querySelector('.event-container-v2')

if (containerV2) {
    const blocks = Array.from(containerV2.querySelectorAll('.event-block-v2'))

    // only clone for the loop illusion when there's more than one real slide to loop through
    const slides = blocks.length > 1 ? [...blocks, blocks[0].cloneNode(true)] : blocks
    if (slides.length > blocks.length) containerV2.appendChild(slides[slides.length - 1])
    let index = 0

    // offsetTop is relative to the nearest positioned ancestor (the page here, not this container), so measure manually
    const topWithin = (el) => containerV2.scrollTop + el.getBoundingClientRect().top - containerV2.getBoundingClientRect().top

    

    const animateScrollTop = (target, onDone) => {
        // mandatory snap re-snaps after every discrete scrollTop write, making the tween look instant unless disabled
        containerV2.style.scrollSnapType = 'none'
        const startTop = containerV2.scrollTop
        const delta = target - startTop
        const startTime = performance.now()

        const step = (now) => {
            const progress = Math.min((now - startTime) / SCROLL_DURATION, 1)
            const eased = SCROLL_EASING(progress)
            containerV2.scrollTop = startTop + delta * eased

            if (progress < 1) {
                requestAnimationFrame(step)
            } else {
                containerV2.style.scrollSnapType = ''
                if (onDone) onDone()
            }
        }

        requestAnimationFrame(step)
    }

    // block heights vary, so re-target whichever slide is currently in view to size the container
    const observer = new ResizeObserver(() => {
        containerV2.style.height = `${slides[index].getBoundingClientRect().height}px`
        containerV2.classList.add('active')
    })
    if (slides.length > 0) observer.observe(slides[index])

    const tick = () => {
        // wrap with modulo so a throttled background tab can't push index past the last slide
        index = (index + 1) % slides.length

        // the tween's own completion callback replaces the scrollend/timeout guesswork needed by native smooth scroll
        animateScrollTop(topWithin(slides[index]), () => {
            observer.disconnect()
            observer.observe(slides[index])

            if (index === slides.length - 1) {
                containerV2.scrollTop = topWithin(blocks[0])
                index = 0
                observer.disconnect()
                observer.observe(slides[index])
            }
        })
    }

    if (slides.length > 1) {
        let intervalId = setInterval(tick, SCROLL_INTERVAL)

        containerV2.addEventListener('mouseenter', () => clearInterval(intervalId))
        containerV2.addEventListener('mouseleave', () => { intervalId = setInterval(tick, SCROLL_INTERVAL) })
    }
}
