package com.novemax.expense.dto;

import com.novemax.expense.model.Role;
import java.util.UUID;
import lombok.Data;

@Data
public class UserDTO {
    private UUID id;
    private String nome;
    private String email;
    private Role role;
}