/**
 * Matrix Rain Background Effect
 * Interactive Matrix-style character rain with mouse interaction
 * Only for login page
 */

class MatrixRain {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        
        this.ctx = this.canvas.getContext('2d');
        this.mouseX = 0;
        this.mouseY = 0;
        this.hoverRadius = 50; // Mouse hover radius
        
        // Matrix characters (katakana, numbers, symbols)
        this.chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|;:,.<>?';
        this.charArray = this.chars.split('');
        
        // Configuration
        this.fontSize = 14;
        this.columns = 0;
        this.drops = [];
        this.animationId = null;
        this.isRunning = false;
        
        // Performance optimization
        this.lastMouseUpdate = 0;
        this.mouseUpdateThrottle = 16; // ~60fps
        
        this.init();
    }
    
    init() {
        this.resize();
        this.setupEventListeners();
        this.start();
    }
    
    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        this.columns = Math.floor(this.canvas.width / this.fontSize);
        
        // Initialize drops array
        this.drops = [];
        for (let i = 0; i < this.columns; i++) {
            const speed = Math.random() * 2 + 1;
            this.drops[i] = {
                y: Math.random() * -500 - 100, // Start well above screen (distributed)
                speed: speed, // Random speed
                originalSpeed: speed, // Store original speed for reset
                length: Math.floor(Math.random() * 20) + 10, // Random trail length
                chars: [] // Store character positions for mouse interaction
            };
        }
    }
    
    setupEventListeners() {
        window.addEventListener('resize', () => {
            this.resize();
        });
        
        // Mouse tracking with throttling
        document.addEventListener('mousemove', (e) => {
            const now = Date.now();
            if (now - this.lastMouseUpdate < this.mouseUpdateThrottle) return;
            this.lastMouseUpdate = now;
            
            this.mouseX = e.clientX;
            this.mouseY = e.clientY;
        });
        
        // Pause on visibility change for performance
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stop();
            } else {
                this.start();
            }
        });
    }
    
    getRandomChar() {
        return this.charArray[Math.floor(Math.random() * this.charArray.length)];
    }
    
    draw() {
        // Fade effect - semi-transparent black overlay
        this.ctx.fillStyle = 'rgba(0, 0, 0, 0.05)';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw each column
        for (let i = 0; i < this.columns; i++) {
            const drop = this.drops[i];
            const x = i * this.fontSize;
            
            // Store character positions for this column (for mouse interaction)
            const charPositions = [];
            
            // Draw trail of characters
            for (let j = 0; j < drop.length; j++) {
                const y = drop.y - (j * this.fontSize);
                
                // Skip if completely off screen (but allow negative y for smooth entry)
                if (y < -this.fontSize * 2 || y > this.canvas.height + this.fontSize) {
                    continue;
                }
                
                // Calculate distance from mouse
                const distance = Math.sqrt(
                    Math.pow(x - this.mouseX, 2) + Math.pow(y - this.mouseY, 2)
                );
                
                // Determine if character is near mouse
                const isNearMouse = distance < this.hoverRadius;
                
                // Calculate opacity based on position in trail (head is brightest)
                const trailOpacity = 1 - (j / drop.length) * 0.7;
                
                // Enhanced brightness and size when near mouse
                let brightness = trailOpacity;
                let size = this.fontSize;
                let glowIntensity = 0.3;
                
                if (isNearMouse) {
                    // Brighten and enlarge
                    brightness = Math.min(1, trailOpacity + 0.5);
                    size = this.fontSize * (1 + (1 - distance / this.hoverRadius) * 0.3);
                    glowIntensity = 0.8;
                    
                    // Slow down the drop slightly
                    drop.speed = Math.max(0.5, drop.speed * 0.95);
                } else {
                    // Reset speed gradually to original
                    const originalSpeed = drop.originalSpeed || (Math.random() * 2 + 1);
                    drop.speed = Math.min(originalSpeed, drop.speed * 1.01);
                }
                
                // Get random character for this position
                const char = this.getRandomChar();
                
                // Set font
                this.ctx.font = `${size}px 'Courier New', monospace`;
                
                // Draw glow effect
                if (glowIntensity > 0) {
                    this.ctx.shadowBlur = 10 * glowIntensity;
                    this.ctx.shadowColor = `rgba(57, 255, 20, ${glowIntensity * brightness})`;
                } else {
                    this.ctx.shadowBlur = 0;
                }
                
                // Calculate color based on brightness
                const greenValue = Math.floor(135 + (brightness * 120)); // 135-255 range
                const alpha = brightness;
                this.ctx.fillStyle = `rgba(57, ${greenValue}, 20, ${alpha})`;
                
                // Draw character
                this.ctx.fillText(char, x, y);
                
                // Store for potential future use
                charPositions.push({ x, y, char, brightness, size });
            }
            
            // Move drop down continuously
            drop.y += drop.speed;
            
            // Reset drop when it goes completely off screen (with buffer)
            const maxY = this.canvas.height + (drop.length * this.fontSize) + 50;
            if (drop.y > maxY) {
                drop.y = Math.random() * -200 - 50; // Start well above screen
                const newSpeed = Math.random() * 2 + 1;
                drop.speed = newSpeed;
                drop.originalSpeed = newSpeed; // Store original speed
                drop.length = Math.floor(Math.random() * 20) + 10;
            }
        }
        
        // Reset shadow for next frame
        this.ctx.shadowBlur = 0;
    }
    
    animate() {
        if (!this.isRunning) return;
        
        this.draw();
        this.animationId = requestAnimationFrame(() => this.animate());
    }
    
    start() {
        if (this.isRunning) return;
        this.isRunning = true;
        this.animate();
    }
    
    stop() {
        this.isRunning = false;
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
            this.animationId = null;
        }
    }
    
    destroy() {
        this.stop();
        window.removeEventListener('resize', this.resize);
    }
}

// Initialize when DOM is ready
let matrixRain = null;

document.addEventListener('DOMContentLoaded', () => {
    // Only initialize on login page
    if (document.getElementById('matrix-canvas')) {
        matrixRain = new MatrixRain('matrix-canvas');
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (matrixRain) {
        matrixRain.destroy();
    }
});
