const container = document.querySelector('.event-container')
const blocks = Array.from(container.querySelectorAll('.event-block'))

// clone the first block onto the end so the carousel can keep scrolling forward
const clone = blocks[0].cloneNode(true)
container.appendChild(clone)

const slides = [...blocks, clone]
let index = 0

setInterval(() => {
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
}, 5000)
