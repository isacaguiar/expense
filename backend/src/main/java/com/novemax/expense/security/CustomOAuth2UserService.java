package com.novemax.expense.security;

import com.novemax.expense.model.Role;
import com.novemax.expense.model.User;
import com.novemax.expense.repository.UserRepository;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.security.oauth2.core.user.OAuth2User;
import org.springframework.security.oauth2.client.userinfo.DefaultOAuth2UserService;
import org.springframework.security.oauth2.client.userinfo.OAuth2UserRequest;
import org.springframework.security.oauth2.client.userinfo.OAuth2UserService;
import org.springframework.security.oauth2.core.OAuth2AuthenticationException;
import org.springframework.stereotype.Service;

import java.util.Map;
import java.util.Optional;
import java.util.UUID;

@Service
public class CustomOAuth2UserService implements OAuth2UserService<OAuth2UserRequest, OAuth2User> {

    private final UserRepository userRepository;

    @Autowired
    public CustomOAuth2UserService(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    @Override
    public OAuth2User loadUser(OAuth2UserRequest userRequest) throws OAuth2AuthenticationException {
        OAuth2User user = new DefaultOAuth2UserService().loadUser(userRequest);

        Map<String, Object> attributes = user.getAttributes();
        String email = (String) attributes.get("email");
        String nome = (String) attributes.get("name");

        Optional<User> existente = userRepository.findByEmail(email);
        if (existente.isEmpty()) {
            User novo = new User();
            novo.setId(UUID.randomUUID());
            novo.setEmail(email);
            novo.setName(nome);
            novo.setPassword(""); // não se aplica
            novo.setRole(Role.COMMON);
            userRepository.save(novo);
        }

        return user;
    }
}