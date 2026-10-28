// The finished object in 3D (the plan page and the reverse build): every cut part a box
// at its real size, where the layout puts it, its large faces covered with its own wood
// taken out of the user's photo. Drag to turn, scroll or pinch to zoom.
// three.js is vendored in public/vendor/three (MIT licence).
import * as THREE from '/vendor/three/three.module.min.js'
import { OrbitControls } from '/vendor/three/OrbitControls.js'

const AXES = ['x', 'y', 'z']
// For a face whose normal is this axis, the axis its texture runs along (BoxGeometry UVs).
const FACE_U = { x: 'z', y: 'x', z: 'x' }

async function loadImage(url) {
  const image = new Image()
  image.src = url
  try {
    await image.decode()
    return image
  } catch {
    return null
  }
}

/** The photo transform that straightens a piece's outline into a length x width rectangle. */
function photoTransform(outline, length, width) {
  if (!outline || outline.length !== 4 || length <= 0 || width <= 0) return null
  const [p0, p1, , p3] = outline
  let origin = p0
  let u = [p1[0] - p0[0], p1[1] - p0[1]]
  let v = [p3[0] - p0[0], p3[1] - p0[1]]
  if (Math.hypot(...u) < Math.hypot(...v)) [u, v] = [v, u]
  if (u[0] * v[1] - u[1] * v[0] < 0) {
    origin = [origin[0] + v[0], origin[1] + v[1]]
    v = [-v[0], -v[1]]
  }
  const det = u[0] * v[1] - u[1] * v[0]
  if (Math.abs(det) < 1e-6) return null
  const a = (length * v[1]) / det
  const c = (-length * v[0]) / det
  const b = (-width * u[1]) / det
  const d = (width * u[0]) / det
  return [a, b, c, d, -(a * origin[0] + c * origin[1]), -(b * origin[0] + d * origin[1])]
}

/** The part's rectangle cut out of the photo, length along the canvas width. */
function partCanvas(part, piece, image, photo) {
  if (!image || !photo || !piece) return null
  const matrix = photoTransform(piece.outline, Math.max(piece.length_mm, piece.width_mm), Math.min(piece.length_mm, piece.width_mm))
  if (!matrix) return null
  const scale = 512 / Math.max(part.length_mm, part.width_mm)
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(8, Math.round(part.length_mm * scale))
  canvas.height = Math.max(8, Math.round(part.width_mm * scale))
  const context = canvas.getContext('2d')
  context.setTransform(scale, 0, 0, scale, -part.x * scale, -part.y * scale)
  context.transform(...matrix)
  context.drawImage(image, 0, 0, photo.width, photo.height)
  return canvas
}

function edgeColour(canvas, fallback) {
  if (!canvas) return fallback
  const small = document.createElement('canvas')
  small.width = small.height = 1
  const context = small.getContext('2d')
  context.drawImage(canvas, 0, 0, 1, 1)
  const [r, g, b] = context.getImageData(0, 0, 1, 1).data
  return `rgb(${Math.round(r * 0.8)}, ${Math.round(g * 0.8)}, ${Math.round(b * 0.8)})`
}

async function mount(host) {
  const data = JSON.parse(host.dataset.scene3d)
  if (!data.assembly?.length) return
  const image = data.photo ? await loadImage(data.photo.url) : null
  const width = host.clientWidth || 600
  const height = host.clientHeight || 420

  let renderer
  try {
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true })
  } catch {
    return // No WebGL: the still picture stays.
  }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.setSize(width, height)
  renderer.shadowMap.enabled = true
  renderer.outputColorSpace = THREE.SRGBColorSpace

  const scene = new THREE.Scene()
  scene.add(new THREE.HemisphereLight(0xffffff, 0xd8cbb5, 1.6))
  const sun = new THREE.DirectionalLight(0xffffff, 2.2)
  sun.castShadow = true
  sun.shadow.mapSize.set(2048, 2048)
  scene.add(sun)

  const group = new THREE.Group()
  for (const item of data.assembly) {
    const part = data.placements.find((placement) => placement.number === item.number)
    if (!part) continue
    const piece = data.pieces.find((candidate) => candidate.id === part.piece_id)
    const thicknessAxis = AXES.find((axis) => axis !== item.length_axis && axis !== item.width_axis)
    const size = { x: 0, y: 0, z: 0 }
    size[item.length_axis] = part.length_mm
    size[item.width_axis] = part.width_mm
    size[thicknessAxis] = Math.max(part.thickness_mm, 1)

    const metal = piece?.kind === 'sheet_metal'
    const canvas = partCanvas(part, piece, image, data.photo)
    const plain = metal ? '#a7adb3' : '#d9b48f'
    const edges = new THREE.MeshStandardMaterial({ color: edgeColour(canvas, plain), roughness: 0.85, metalness: metal ? 0.6 : 0 })
    const materials = AXES.flatMap((axis) => {
      if (axis !== thicknessAxis) return [edges, edges]
      if (!canvas) {
        const face = new THREE.MeshStandardMaterial({ color: plain, roughness: 0.8, metalness: metal ? 0.6 : 0 })
        return [face, face]
      }
      const texture = new THREE.CanvasTexture(canvas)
      texture.colorSpace = THREE.SRGBColorSpace
      if (FACE_U[axis] !== item.length_axis) {
        texture.center.set(0.5, 0.5)
        texture.rotation = Math.PI / 2
      }
      const face = new THREE.MeshStandardMaterial({ map: texture, roughness: metal ? 0.45 : 0.8, metalness: metal ? 0.6 : 0 })
      return [face, face]
    })
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(size.x, size.y, size.z), materials)
    mesh.position.set(item.x + size.x / 2, item.y + size.y / 2, item.z + size.z / 2)
    mesh.castShadow = true
    mesh.receiveShadow = true
    group.add(mesh)
  }
  scene.add(group)

  const box = new THREE.Box3().setFromObject(group)
  const centre = box.getCenter(new THREE.Vector3())
  const extent = box.getSize(new THREE.Vector3())
  group.position.set(-centre.x, -box.min.y, -centre.z)
  const radius = Math.max(extent.x, extent.y, extent.z, 100)

  const floor = new THREE.Mesh(new THREE.CircleGeometry(radius * 1.6, 48), new THREE.ShadowMaterial({ opacity: 0.18 }))
  floor.rotation.x = -Math.PI / 2
  floor.receiveShadow = true
  scene.add(floor)
  sun.position.set(radius * 1.2, radius * 2.2, radius * 1.5)
  Object.assign(sun.shadow.camera, { left: -radius * 1.5, right: radius * 1.5, top: radius * 1.5, bottom: -radius * 1.5, far: radius * 6 })

  const camera = new THREE.PerspectiveCamera(35, width / height, radius / 50, radius * 20)
  camera.position.set(radius * 1.6, radius * 1.2, radius * 2.1)
  const controls = new OrbitControls(camera, renderer.domElement)
  controls.target.set(0, extent.y / 2, 0)
  controls.enableDamping = true
  controls.autoRotate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches
  controls.autoRotateSpeed = 1.2
  controls.minDistance = radius * 0.6
  controls.maxDistance = radius * 6
  controls.addEventListener('start', () => (controls.autoRotate = false))
  controls.update()

  // The still picture leaves only once the 3D is ready.
  host.replaceChildren(renderer.domElement)
  host.classList.add('live')
  new ResizeObserver(() => {
    const w = host.clientWidth
    const h = host.clientHeight
    if (!w || !h) return
    renderer.setSize(w, h)
    camera.aspect = w / h
    camera.updateProjectionMatrix()
  }).observe(host)
  renderer.setAnimationLoop(() => {
    controls.update()
    renderer.render(scene, camera)
  })
}

for (const host of document.querySelectorAll('[data-scene3d]')) {
  mount(host).catch((error) => console.error('[recupbuild] 3D view failed', error))
}
