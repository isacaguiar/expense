package com.novemax.expense.controller;

import com.novemax.expense.model.Role;
import com.novemax.expense.model.User;
import com.novemax.expense.repository.UserRepository;
import com.novemax.expense.security.JwtUtil;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.web.bind.annotation.*;

import java.util.*;

@RestController
@RequestMapping("/auth")
@Tag(name = "Authentication")
public class AuthController {

    private final JwtUtil jwtUtil;
    private final UserRepository userRepository;
    private final BCryptPasswordEncoder passwordEncoder;

    private final long ACCESS_TOKEN_EXPIRATION = 15 * 60 * 1000; // 15 minutos
    private final long REFRESH_TOKEN_EXPIRATION = 7 * 24 * 60 * 60 * 1000; // 7 dias

    public AuthController(JwtUtil jwtUtil, UserRepository userRepository) {
        this.jwtUtil = jwtUtil;
        this.userRepository = userRepository;
        this.passwordEncoder = new BCryptPasswordEncoder();
    }

    @PostMapping("/login")
    public Map<String, String> login(@RequestBody Map<String, String> body) {
        String email = body.get("email");
        String password = body.get("password");

        Optional<User> userOpt = userRepository.findByEmail(email);
        if (userOpt.isPresent() && passwordEncoder.matches(password, userOpt.get().getPassword())) {
            String accessToken = jwtUtil.generateToken(email, ACCESS_TOKEN_EXPIRATION);
            String refreshToken = jwtUtil.generateToken(email, REFRESH_TOKEN_EXPIRATION);
            return Map.of("accessToken", accessToken, "refreshToken", refreshToken);
        } else {
            throw new RuntimeException("Credenciais inválidas");
        }
    }

    @PostMapping("/refresh")
    public Map<String, String> refresh(@RequestBody Map<String, String> body) {
        String refreshToken = body.get("refreshToken");
        String username = jwtUtil.extractUsername(refreshToken);

        if (jwtUtil.isTokenValid(refreshToken, username)) {
            String newAccessToken = jwtUtil.generateToken(username, ACCESS_TOKEN_EXPIRATION);
            return Map.of("accessToken", newAccessToken);
        } else {
            throw new RuntimeException("Refresh token inválido ou expirado");
        }
    }

    @PostMapping("/register")
    public User register(@RequestBody Map<String, String> body) {
        String email = body.get("email");
        if (userRepository.findByEmail(email).isPresent()) {
            throw new RuntimeException("E-mail já cadastrado");
        }

        User user = new User();
        user.setId(UUID.randomUUID());
        user.setName(body.get("name"));
        user.setEmail(email);
        user.setPassword(passwordEncoder.encode(body.get("password")));
        user.setRole(Role.valueOf(body.getOrDefault("role", "USER").toUpperCase()));

        return userRepository.save(user);
    }
}