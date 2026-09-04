import { useEffect, useRef } from 'react'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { useReducedMotion } from 'motion/react'
import Reveal from '../components/Reveal'

gsap.registerPlugin(ScrollTrigger)

const CORE_SERVICES = ['Branding', 'Digital Design', 'Diseño web y desarrollo', 'Dirección creativa', 'Creación de contenido']
const GROWTH_SERVICES = ['Video y animación', 'Automatización con IA', 'Marketing & Growth']

export default function Services() {
  const sectionRef = useRef<HTMLDivElement>(null)
  const reduce = useReducedMotion()

  useEffect(() => {
    if (reduce || !sectionRef.current) return

    const ctx = gsap.context(() => {
      const rows = gsap.utils.toArray<HTMLElement>('[data-service-row]')
      rows.forEach((row) => {
        ScrollTrigger.create({
          trigger: row,
          start: 'top 65%',
          end: 'bottom 35%',
          toggleClass: { targets: row, className: 'is-active' },
        })
      })
    }, sectionRef)

    return () => ctx.revert()
  }, [reduce])

  return (
    <section id="servicios" ref={sectionRef} className="relative overflow-hidden bg-panel py-24 md:py-32">
      <div className="glow-blob absolute -left-40 top-1/3 h-[50vh] w-[50vh] rounded-full bg-electric/15" aria-hidden />

      <div className="relative mx-auto max-w-[1400px] px-6 md:px-10">
        <div className="grid gap-12 md:grid-cols-[0.9fr_1.4fr] md:gap-16">
          <div className="relative md:sticky md:top-28 md:self-start">
            <span
              aria-hidden
              className="text-outline pointer-events-none absolute -left-4 -top-10 select-none font-display text-[8rem] leading-none opacity-30 md:text-[11rem]"
            >
              03
            </span>

            <Reveal className="relative">
              <span className="eyebrow">/ Servicios</span>
              <h2 className="font-display mt-4 text-3xl leading-[1.05] text-bone sm:text-4xl md:text-5xl">
                Lo que <span className="text-electric-soft">hacemos</span>
              </h2>
              <p className="mt-6 max-w-sm text-sm leading-relaxed text-mist/65 md:text-base">
                El branding construye una identidad clara y reconocible. Combina estrategia, lenguaje
                visual, tono y diseño para crear consistencia en cada punto de contacto y una conexión
                más fuerte con tu audiencia.
              </p>
            </Reveal>
          </div>

          <div className="grid gap-2">
            {CORE_SERVICES.map((service, i) => (
              <ServiceRow key={service} label={service} index={i + 1} />
            ))}
            <div className="my-4 h-px bg-white/10" />
            {GROWTH_SERVICES.map((service, i) => (
              <ServiceRow key={service} label={service} index={CORE_SERVICES.length + i + 1} />
            ))}
          </div>
        </div>
      </div>
    </section>
  )
}

function ServiceRow({ label, index }: { label: string; index: number }) {
  return (
    <div
      data-service-row
      className="group relative flex items-center gap-6 border-b border-white/10 py-5 transition-colors duration-500 [&.is-active]:border-electric-soft/40 md:py-6"
    >
      <span className="font-mono text-xs text-mist/40 transition-colors duration-500 group-[.is-active]:text-electric-soft">
        {String(index).padStart(2, '0')}
      </span>
      <span className="font-display text-xl text-mist/50 transition-all duration-500 group-[.is-active]:translate-x-2 group-[.is-active]:text-bone sm:text-2xl md:text-3xl">
        {label}
      </span>
    </div>
  )
}
