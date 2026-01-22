/**
 * Stars Background Effect
 * Animated stars falling from top to bottom
 * For all pages except login
 */

class StarsBackground {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        
        this.ctx = this.canvas.getContext('2d');
        
        // Star configuration
        this.stars = [];
        this.starCount = 400; // Total number of stars
        
        // Performance optimization
        this.animationId = null;
        this.isRunning = false;
        
        this.init();
    }
    
    init() {
        this.resize();
        this.createStars();
        this.setupEventListeners();
        this.start();
    }
    
    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        this.canvas.style.position = 'fixed';
        this.canvas.style.top = '0';
        this.canvas.style.left = '0';
        this.canvas.style.width = '100%';
        this.canvas.style.height = '100%';
        this.canvas.style.zIndex = '0';
        this.canvas.style.pointerEvents = 'none';
        
        // Recreate stars on resize if needed
        if (this.stars.length === 0) {
            this.createStars();
        }
    }
    
    createStars() {
        this.stars = [];
        
        // Create stars in three layers (different sizes and speeds)
        const layer1Count = Math.floor(this.starCount * 0.5); // 50% - small stars
        const layer2Count = Math.floor(this.starCount * 0.3); // 30% - medium stars
        const layer3Count = this.starCount - layer1Count - layer2Count; // 20% - large stars
        
        // Layer 1: Small stars (1px) - fast
        for (let i = 0; i < layer1Count; i++) {
            this.stars.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height * 2, // Start at different heights
                size: 1,
                speed: Math.random() * 0.5 + 0.3,
                opacity: Math.random() * 0.5 + 0.3
            });
        }
        
        // Layer 2: Medium stars (2px) - medium speed
        for (let i = 0; i < layer2Count; i++) {
            this.stars.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height * 2,
                size: 2,
                speed: Math.random() * 0.4 + 0.2,
                opacity: Math.random() * 0.4 + 0.4
            });
        }
        
        // Layer 3: Large stars (3px) - slow
        for (let i = 0; i < layer3Count; i++) {
            this.stars.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height * 2,
                size: 3,
                speed: Math.random() * 0.3 + 0.1,
                opacity: Math.random() * 0.3 + 0.5
            });
        }
    }
    
    setupEventListeners() {
        window.addEventListener('resize', () => {
            this.resize();
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
    
    draw() {
        // Clear canvas with transparent background
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw each star
        for (let i = 0; i < this.stars.length; i++) {
            const star = this.stars[i];
            
            // Move star down
            star.y += star.speed;
            
            // Reset star when it goes off screen
            if (star.y > this.canvas.height + 10) {
                star.y = -10;
                star.x = Math.random() * this.canvas.width;
            }
            
            // Draw star
            this.ctx.fillStyle = `rgba(255, 255, 255, ${star.opacity})`;
            this.ctx.beginPath();
            this.ctx.arc(star.x, star.y, star.size / 2, 0, Math.PI * 2);
            this.ctx.fill();
        }
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
let starsBackground = null;

document.addEventListener('DOMContentLoaded', () => {
    // Only initialize on pages with stars canvas (not login)
    if (document.getElementById('stars-canvas') && !document.body.classList.contains('login-page')) {
        starsBackground = new StarsBackground('stars-canvas');
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (starsBackground) {
        starsBackground.destroy();
    }
});
