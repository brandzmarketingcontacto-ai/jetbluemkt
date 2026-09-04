import { useRef } from 'react'
import { ArrowLeft, ArrowRight, CaretLeft, CaretRight } from '@phosphor-icons/react'
import Reveal from '../components/Reveal'

const PROJECTS = [
  {
    seed: 'jetblue-editorial-street-01',
    alt: 'Dirección de arte editorial para campaña de moda urbana',
    span: 'md:col-span-4',
  },
  {
    seed: 'jetblue-editorial-leather-02',
    alt: 'Fotografía de producto y estilismo para marca de moda',
    span: 'md:col-span-5',
  },
  {
    seed: 'jetblue-editorial-duo-03',
    alt: 'Sesión de imagen de marca para dúo creativo',
    span: 'md:col-span-3',
  },
]

export default function About() {
  const trackRef = useRef<HTMLDivElement>(null)

  function scrollByCard(direction: 1 | -1) {
    const track = trackRef.current
    if (!track) return
    const card = track.querySelector('[data-card]') as HTMLElement | null
    const distance = card ? card.offsetWidth + 24 : 320
    track.scrollBy({ left: distance * direction, behavior: 'smooth' })
  }

  return (
    <section id="about" className="relative bg-ink py-24 md:py-32">
      <div className="mx-auto max-w-[1400px] px-6 md:px-10">
        <div className="flex items-start justify-between gap-6">
          <Reveal>
            <h2 className="font-display max-w-3xl text-3xl leading-[1.05] text-bone sm:text-4xl md:text-5xl lg:text-6xl">
              Creamos marcas, <span className="text-electric-soft">experiencias</span>
              <br />
              y sistemas hechos para moverte.
            </h2>
          </Reveal>
          <Reveal delay={0.1} className="hidden shrink-0 pt-2 sm:block">
            <span className="eyebrow">/ Sobre nosotros</span>
          </Reveal>
        </div>

        <Reveal delay={0.15} className="mt-8 max-w-2xl">
          <p className="text-base leading-relaxed text-mist/75 md:text-lg">
            <span className="font-semibold text-bone">BRANDZMKT</span> es el estudio creativo detrás de
            JetBlue Studio: un equipo para marcas modernas enfocado en branding, diseño digital, video,
            automatización con IA y marketing de crecimiento.
          </p>
        </Reveal>

        <div className="relative mt-16 md:mt-20">
          <span
            aria-hidden
            className="text-outline pointer-events-none absolute -top-16 right-0 select-none font-display text-[9rem] leading-none opacity-40 md:-top-24 md:text-[13rem]"
          >
            02
          </span>

          <div className="relative flex items-center justify-between gap-6">
            <Reveal delay={0.2}>
              <span className="eyebrow">Proyectos seleccionados</span>
            </Reveal>
            <Reveal delay={0.2} className="hidden gap-2 sm:flex">
              <button
                type="button"
                onClick={() => scrollByCard(-1)}
                className="grid h-11 w-11 place-items-center rounded-full border border-white/10 text-mist/70 transition-colors hover:border-electric-soft/50 hover:text-bone"
                aria-label="Proyecto anterior"
              >
                <CaretLeft size={18} />
              </button>
              <button
                type="button"
                onClick={() => scrollByCard(1)}
                className="grid h-11 w-11 place-items-center rounded-full border border-white/10 text-mist/70 transition-colors hover:border-electric-soft/50 hover:text-bone"
                aria-label="Siguiente proyecto"
              >
                <CaretRight size={18} />
              </button>
            </Reveal>
          </div>

          <div
            ref={trackRef}
            className="mt-8 flex snap-x snap-mandatory gap-6 overflow-x-auto pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          >
            {PROJECTS.map((project, i) => (
              <Reveal
                key={project.seed}
                delay={0.1 * i}
                className="group relative aspect-[3/4] w-[72vw] shrink-0 snap-start overflow-hidden rounded-2xl border border-white/10 sm:w-[46vw] md:w-[30vw] lg:w-[26vw]"
              >
                <div data-card className="absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,_var(--color-panel-soft),_var(--color-ink))]">
                  <img
                    src={`https://picsum.photos/seed/${project.seed}/800/1000`}
                    alt={project.alt}
                    loading="lazy"
                    onError={(e) => {
                      e.currentTarget.style.opacity = '0'
                    }}
                    className="h-full w-full object-cover grayscale-[35%] transition-transform duration-700 group-hover:scale-105"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-ink/70 via-electric/10 to-transparent mix-blend-multiply" />
                </div>
              </Reveal>
            ))}
          </div>

          <div className="mt-10 flex items-center justify-between border-t border-white/10 pt-6">
            <button
              type="button"
              onClick={() => scrollByCard(-1)}
              className="group flex items-center gap-3 text-sm text-mist/60 transition-colors hover:text-bone"
            >
              <ArrowLeft size={16} className="transition-transform group-hover:-translate-x-1" />
              Anterior
            </button>
            <button
              type="button"
              onClick={() => scrollByCard(1)}
              className="group flex items-center gap-3 text-sm text-mist/60 transition-colors hover:text-bone"
            >
              Siguiente
              <ArrowRight size={16} className="transition-transform group-hover:translate-x-1" />
            </button>
          </div>
        </div>
      </div>
    </section>
  )
}
