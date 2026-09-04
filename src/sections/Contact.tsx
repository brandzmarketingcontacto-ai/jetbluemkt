import { useState, type FormEvent } from 'react'
import { ArrowUpRight } from '@phosphor-icons/react'
import Reveal from '../components/Reveal'
import MagneticButton from '../components/MagneticButton'

export default function Contact() {
  const [status, setStatus] = useState<'idle' | 'sent'>('idle')

  function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setStatus('sent')
  }

  return (
    <section id="contacto" className="relative overflow-hidden bg-ink py-24 md:py-32">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,_var(--color-panel-soft),_var(--color-ink)_70%)]" aria-hidden />
      <img
        src="https://picsum.photos/seed/jetblue-contact-portrait/1600/2000"
        alt="Retrato con iluminación azul, representando la energía creativa del estudio"
        loading="lazy"
        onError={(e) => {
          e.currentTarget.style.opacity = '0'
        }}
        className="absolute inset-0 h-full w-full object-cover opacity-30 grayscale [mask-image:linear-gradient(to_top,black,transparent_75%)]"
      />
      <div className="absolute inset-0 bg-gradient-to-t from-ink via-ink/80 to-ink" aria-hidden />
      <div
        className="glow-blob absolute right-0 top-1/4 h-[55vh] w-[55vh] rounded-full bg-electric/25"
        aria-hidden
      />

      <div className="relative mx-auto max-w-[1400px] px-6 md:px-10">
        <Reveal>
          <span className="eyebrow">/ Contacto</span>
        </Reveal>

        <Reveal delay={0.1}>
          <h2 className="font-display mt-6 max-w-3xl text-4xl leading-[1.02] text-bone sm:text-5xl md:text-6xl lg:text-7xl">
            ¿Tienes una idea?
            <br />
            <span className="text-electric-soft">Hagámoslo real.</span>
          </h2>
        </Reveal>

        <div className="mt-14 grid gap-12 md:mt-20 md:grid-cols-2 md:items-start md:gap-16">
          <Reveal delay={0.15} className="max-w-md">
            <p className="text-base leading-relaxed text-mist/70 md:text-lg">
              Las grandes ideas empiezan con una conversación. Cuéntanos sobre tu marca, proyecto o idea
              y creemos juntos algo imposible de ignorar.
            </p>

            <MagneticButton
              href="mailto:kevin.garza@jetbluestudio.com"
              className="mt-8 inline-flex items-center gap-2 rounded-full bg-electric px-7 py-4 text-sm font-semibold text-ink transition-colors hover:bg-electric-soft"
            >
              Empezar proyecto
              <ArrowUpRight size={18} weight="bold" />
            </MagneticButton>
          </Reveal>

          <Reveal delay={0.2}>
            <form onSubmit={handleSubmit} className="grid gap-5" noValidate>
              <Field label="Nombre" name="nombre" type="text" autoComplete="name" required />
              <Field label="Email" name="email" type="email" autoComplete="email" required />
              <div className="grid gap-2">
                <label htmlFor="mensaje" className="text-xs font-medium tracking-wide text-mist/60">
                  Cuéntanos tu proyecto
                </label>
                <textarea
                  id="mensaje"
                  name="mensaje"
                  rows={4}
                  required
                  className="resize-none rounded-xl border border-white/15 bg-white/[0.03] px-4 py-3 text-sm text-bone placeholder:text-mist/30 focus:border-electric-soft/60 focus:outline-none focus:ring-2 focus:ring-electric-soft/20"
                  placeholder="Marca, plazos, presupuesto aproximado..."
                />
              </div>

              <button
                type="submit"
                className="mt-2 inline-flex items-center justify-center gap-2 rounded-full border border-electric-soft/40 bg-electric/10 px-6 py-3.5 text-sm font-semibold text-bone transition-colors hover:bg-electric/20"
              >
                {status === 'sent' ? '¡Mensaje enviado!' : 'Enviar mensaje'}
              </button>
              <p aria-live="polite" className="sr-only">
                {status === 'sent' ? 'Mensaje enviado correctamente' : ''}
              </p>
            </form>
          </Reveal>
        </div>
      </div>
    </section>
  )
}

function Field({
  label,
  name,
  type,
  autoComplete,
  required,
}: {
  label: string
  name: string
  type: string
  autoComplete: string
  required?: boolean
}) {
  return (
    <div className="grid gap-2">
      <label htmlFor={name} className="text-xs font-medium tracking-wide text-mist/60">
        {label}
      </label>
      <input
        id={name}
        name={name}
        type={type}
        autoComplete={autoComplete}
        required={required}
        className="rounded-xl border border-white/15 bg-white/[0.03] px-4 py-3 text-sm text-bone placeholder:text-mist/30 focus:border-electric-soft/60 focus:outline-none focus:ring-2 focus:ring-electric-soft/20"
      />
    </div>
  )
}
