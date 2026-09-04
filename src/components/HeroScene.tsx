import { useRef, useMemo } from 'react'
import { Canvas, useFrame, type ThreeElements } from '@react-three/fiber'
import { Sparkles, Float } from '@react-three/drei'
import * as THREE from 'three'

function Wireframe() {
  const group = useRef<THREE.Group>(null)
  const pointer = useRef({ x: 0, y: 0 })

  useFrame((state, delta) => {
    if (!group.current) return
    group.current.rotation.y += delta * 0.12
    group.current.rotation.x += delta * 0.03

    pointer.current.x += (state.pointer.x - pointer.current.x) * 0.03
    pointer.current.y += (state.pointer.y - pointer.current.y) * 0.03
    group.current.rotation.y += pointer.current.x * 0.15
    group.current.rotation.x += -pointer.current.y * 0.1
  })

  return (
    <Float speed={1.4} rotationIntensity={0.3} floatIntensity={0.6}>
      <group ref={group}>
        <mesh>
          <icosahedronGeometry args={[2.1, 1]} />
          <meshBasicMaterial color="#4f8cff" wireframe transparent opacity={0.55} />
        </mesh>
        <mesh scale={1.35}>
          <icosahedronGeometry args={[2.1, 0]} />
          <meshBasicMaterial color="#2f6bff" wireframe transparent opacity={0.18} />
        </mesh>
      </group>
    </Float>
  )
}

function DriftingParticles(props: ThreeElements['points']) {
  const ref = useRef<THREE.Points>(null)
  const count = 240
  const positions = useMemo(() => {
    const arr = new Float32Array(count * 3)
    for (let i = 0; i < count; i++) {
      const r = 5 + Math.random() * 4
      const theta = Math.random() * Math.PI * 2
      const phi = Math.acos(2 * Math.random() - 1)
      arr[i * 3] = r * Math.sin(phi) * Math.cos(theta)
      arr[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta)
      arr[i * 3 + 2] = r * Math.cos(phi)
    }
    return arr
  }, [])

  useFrame((_, delta) => {
    if (!ref.current) return
    ref.current.rotation.y += delta * 0.02
  })

  return (
    <points ref={ref} {...props}>
      <bufferGeometry>
        <bufferAttribute attach="attributes-position" args={[positions, 3]} />
      </bufferGeometry>
      <pointsMaterial color="#6fa3ff" size={0.035} transparent opacity={0.7} sizeAttenuation />
    </points>
  )
}

export default function HeroScene() {
  return (
    <Canvas
      dpr={[1, 1.8]}
      camera={{ position: [0, 0, 7], fov: 45 }}
      gl={{ antialias: true, alpha: true }}
      className="!absolute inset-0"
    >
      <ambientLight intensity={0.4} />
      <Wireframe />
      <DriftingParticles />
      <Sparkles count={40} scale={9} size={2.5} speed={0.25} color="#8fb6ff" opacity={0.6} />
    </Canvas>
  )
}
