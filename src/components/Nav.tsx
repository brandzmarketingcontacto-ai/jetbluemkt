import { useEffect, useState } from 'react'
import { motion, AnimatePresence } from 'motion/react'
import { List, X } from '@phosphor-icons/react'
import MagneticButton from './MagneticButton'

const LINKS = [
  { label: 'Estudio', href: '#about' },
  { label: 'Servicios', href: '#servicios' },
  { label: 'Proyectos', href: '#proyectos' },
  { label: 'Contacto', href: '#contacto' },
]

export default function Nav() {
  const [scrolled, setScrolled] = useState(false)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    function onScroll() {
      setScrolled(window.scrollY > 24)
    }
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <header
      className={`fixed inset-x-0 top-0 z-50 transition-colors duration-500 ${
        scrolled ? 'bg-ink/80 backdrop-blur-md border-b border-white/5' : 'bg-transparent'
      }`}
    >
      <nav className="mx-auto flex h-16 max-w-[1400px] items-center justify-between px-6 md:h-[72px] md:px-10">
        <a href="#top" className="font-display text-lg tracking-tight text-bone md:text-xl">
          JETBLUE<span className="text-electric-soft">.</span>
        </a>

        <ul className="hidden items-center gap-8 lg:flex">
          {LINKS.map((link) => (
            <li key={link.href}>
              <a
                href={link.href}
                className="text-sm text-mist/80 transition-colors hover:text-bone"
              >
                {link.label}
              </a>
            </li>
          ))}
        </ul>

        <div className="hidden lg:block">
          <MagneticButton
            href="#contacto"
            className="inline-flex items-center rounded-full border border-electric-soft/40 bg-electric/10 px-5 py-2.5 text-sm font-medium text-bone transition-colors hover:bg-electric/20"
          >
            Empezar proyecto
          </MagneticButton>
        </div>

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="grid h-10 w-10 place-items-center text-bone lg:hidden"
          aria-label="Abrir menú"
        >
          {open ? <X size={24} /> : <List size={24} />}
        </button>
      </nav>

      <AnimatePresence>
        {open && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.3, ease: [0.16, 1, 0.3, 1] }}
            className="overflow-hidden border-b border-white/5 bg-ink/95 backdrop-blur-md lg:hidden"
          >
            <ul className="flex flex-col gap-1 px-6 py-6">
              {LINKS.map((link) => (
                <li key={link.href}>
                  <a
                    href={link.href}
                    onClick={() => setOpen(false)}
                    className="block py-3 text-lg text-mist/90"
                  >
                    {link.label}
                  </a>
                </li>
              ))}
              <li className="pt-3">
                <a
                  href="#contacto"
                  onClick={() => setOpen(false)}
                  className="inline-flex items-center rounded-full border border-electric-soft/40 bg-electric/10 px-5 py-2.5 text-sm font-medium text-bone"
                >
                  Empezar proyecto
                </a>
              </li>
            </ul>
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  )
}
