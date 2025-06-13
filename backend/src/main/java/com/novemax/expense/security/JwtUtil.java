package com.novemax.expense.security;

import io.jsonwebtoken.*;
import io.jsonwebtoken.security.Keys;
import java.nio.charset.StandardCharsets;
import javax.crypto.SecretKey;
import org.springframework.stereotype.Component;

import java.util.Date;

@Component
public class JwtUtil {

    private static final String SECRET = "sua-chave-super-secreta-com-256-bits-para-hmac1234567890";
    private final SecretKey SECRET_KEY = Keys.hmacShaKeyFor(SECRET.getBytes(StandardCharsets.UTF_8));

    public String generateToken(String username, long expirationMillis) {
        return Jwts.builder()
            .subject(username)                          // substitui setSubject
            .issuer("ExpenseApp")                       // substitui setIssuer
            .issuedAt(new Date())                       // substitui setIssuedAt
            .expiration(new Date(System.currentTimeMillis() + expirationMillis)) // substitui setExpiration
            .signWith(SECRET_KEY) // NOVO formato
            .compact();
    }

    public String extractUsername(String token) {
        Claims claims = Jwts.parser()
            .verifyWith(SECRET_KEY)
            .build()
            .parseSignedClaims(token)
            .getPayload();

        return claims.getSubject(); // 👈 Aqui você extrai o "username" do token
    }

    public boolean isTokenValid(String token, String username) {
        return extractUsername(token).equals(username) && !isExpired(token);
    }

    public boolean isExpired(String token) {
        try {
            Claims claims = Jwts.parser()
                .verifyWith(SECRET_KEY)
                .build()
                .parseSignedClaims(token)
                .getPayload();

            return claims.getExpiration().before(new Date());
        } catch (Exception e) {
            return true; // considera como expirado ou inválido
        }
    }
}