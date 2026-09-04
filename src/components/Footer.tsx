import { EnvelopeSimple, MapPin, Phone } from '@phosphor-icons/react'

export default function Footer() {
  return (
    <footer className="border-t border-white/10 bg-bone text-ink">
      <div className="mx-auto flex max-w-[1400px] flex-col gap-4 px-6 py-6 text-sm sm:flex-row sm:items-center sm:justify-between md:px-10">
        <a
          href="mailto:kevin.garza@jetbluestudio.com"
          className="flex items-center gap-2 font-medium transition-opacity hover:opacity-70"
        >
          <EnvelopeSimple size={16} />
          kevin.garza@jetbluestudio.com
        </a>

        <span className="flex items-center gap-2 font-semibold">
          <MapPin size={16} />
          Las Vegas, NV.
        </span>

        <a
          href="tel:+17027822965"
          className="flex items-center gap-2 font-medium transition-opacity hover:opacity-70"
        >
          <Phone size={16} />
          +1 (702) 782-2965
        </a>
      </div>
    </footer>
  )
}
