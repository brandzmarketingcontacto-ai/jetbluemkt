import { Suspense, lazy } from 'react'
import { motion } from 'motion/react'
import { ArrowDown } from '@phosphor-icons/react'

const HeroScene = lazy(() => import('../components/HeroScene'))

export default function Hero() {
  return (
    <section id="top" className="relative flex min-h-[100dvh] items-center overflow-hidden bg-ink">
      <div className="absolute inset-0">
        <Suspense fallback={null}>
          <HeroScene />
        </Suspense>
      </div>

      <div
        className="glow-blob absolute left-1/2 top-1/2 h-[70vh] w-[70vh] -translate-x-1/2 -translate-y-1/2 rounded-full bg-electric/25"
        aria-hidden
      />

      <div className="relative z-10 mx-auto w-full max-w-[1400px] px-6 pt-24 md:px-10">
        <motion.p
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.6, delay: 0.1 }}
          className="eyebrow mb-6"
        >
          Studio de branding &amp; tecnología · Las Vegas, NV
        </motion.p>

        <h1 className="font-display leading-[0.92] text-bone">
          <motion.span
            initial={{ opacity: 0, y: 40 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.2, ease: [0.16, 1, 0.3, 1] }}
            className="block text-[15vw] sm:text-[13vw] md:text-[9.5vw] lg:text-[8vw]"
          >
            JETBLUE
          </motion.span>
          <motion.span
            initial={{ opacity: 0, y: 40 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.32, ease: [0.16, 1, 0.3, 1] }}
            className="mt-1 flex items-baseline justify-between text-[6vw] sm:text-[5vw] md:text-[3vw] lg:text-[2.4vw]"
          >
            <span className="text-electric-soft">STUDIO</span>
            <span className="text-outline hidden sm:inline">.COM</span>
          </motion.span>
        </h1>

        <div className="mt-10 grid gap-6 md:mt-14 md:grid-cols-[1.1fr_1fr] md:items-end">
          <motion.p
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, delay: 0.5 }}
            className="max-w-md text-sm italic text-mist/70 md:text-base"
          >
            Donde una idea se convierte en una marca.{' '}
            <span className="font-medium not-italic text-mist">Creatividad y tecnología.</span>
          </motion.p>

          <motion.p
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, delay: 0.6 }}
            className="font-display text-2xl leading-tight text-bone md:text-right md:text-3xl lg:text-4xl"
          >
            Creatividad más
            <br />
            allá de lo ordinario.
          </motion.p>
        </div>
      </div>

      <motion.a
        href="#about"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.6, delay: 1 }}
        className="absolute bottom-8 left-1/2 z-10 flex -translate-x-1/2 flex-col items-center gap-2 text-mist/60"
        aria-label="Ir a la siguiente sección"
      >
        <span className="eyebrow !text-[0.6rem] !tracking-[0.3em]">Scroll</span>
        <motion.span
          animate={{ y: [0, 8, 0] }}
          transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
        >
          <ArrowDown size={18} />
        </motion.span>
      </motion.a>
    </section>
  )
}
