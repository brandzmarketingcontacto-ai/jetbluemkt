import type { ReactNode } from 'react'
import { motion, useReducedMotion } from 'motion/react'

interface RevealProps {
  children: ReactNode
  delay?: number
  y?: number
  className?: string
  as?: 'div' | 'span'
}

export default function Reveal({ children, delay = 0, y = 28, className = '', as = 'div' }: RevealProps) {
  const reduce = useReducedMotion()
  const Component = as === 'span' ? motion.span : motion.div

  return (
    <Component
      initial={reduce ? false : { opacity: 0, y }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.3 }}
      transition={{ duration: 0.7, delay, ease: [0.16, 1, 0.3, 1] }}
      className={className}
    >
      {children}
    </Component>
  )
}
